<?php

namespace App\Ingestion;

use App\Ingestion\Enrichment\Salts;
use App\Models\ExclusionRule;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Everything an external collector needs to accept hits exactly like the built-in one:
 * live projects with their ingest keys, hostnames, settings and exclusion rules, the
 * organization-wide rules, and today's and yesterday's visitor salts.
 *
 * PostgreSQL stays the single source of truth; this is a read-only, versioned view of it.
 * A collector polls it with If-None-Match and can also LISTEN on the
 * `ryvr_ingest_config` channel to refetch as soon as something changes.
 */
class ConfigSnapshot
{
    public const FORMAT = 1;

    public const CHANNEL = 'ryvr_ingest_config';

    public function __construct(private readonly Salts $salts) {}

    /**
     * @return array{body: string, etag: string}
     */
    public function build(): array
    {
        $today = now()->utc();
        $this->salts->forDay($today); // make sure today's salt exists before handing it out

        $salts = DB::table('daily_salts')
            ->whereIn('day', [$today->copy()->addDay()->toDateString(), $today->toDateString(), $today->copy()->subDay()->toDateString()])
            ->orderByDesc('day')
            ->pluck('salt', 'day')
            ->mapWithKeys(fn ($salt, $day) => [substr((string) $day, 0, 10) => (string) $salt])
            ->all();

        $rules = ExclusionRule::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get(['organization_id', 'project_id', 'field', 'operator', 'value']);

        $rule = fn (ExclusionRule $r) => ['field' => $r->field->value, 'operator' => $r->operator->value, 'value' => $r->value];

        $projectRules = $rules->whereNotNull('project_id')->groupBy('project_id');
        $organizationRules = $rules->whereNull('project_id')->groupBy('organization_id')
            ->map(fn ($group) => $group->map($rule)->values()->all())
            ->sortKeys()
            ->all();

        $projects = Project::query()
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'organization_id' => $p->organization_id,
                'ingest_key' => $p->ingest_key,
                'secret_key_digests' => [],
                'hostnames' => $p->hostnames(),
                'settings' => ['hash_routing' => (bool) $p->setting('hash_routing', false)],
                'rules' => ($projectRules->get($p->id) ?? collect())->map($rule)->values()->all(),
                'burst_per_second' => 0,
            ])
            ->all();

        $content = [
            'version' => self::FORMAT,
            'salts' => $salts,
            'projects' => $projects,
            'organization_rules' => (object) $organizationRules,
            'quotas' => (object) [],
            'bot_rules_version' => (string) config('ryvr.ingest.bot_rules_version', '1'),
        ];

        // The ETag covers the content only, so an unchanged configuration answers 304
        // even though generated_at moves on.
        $etag = '"'.hash('sha256', (string) json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)).'"';
        $body = json_encode(['generated_at' => now()->utc()->toIso8601ZuluString()] + $content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['body' => $body, 'etag' => $etag];
    }

    /**
     * Detached Ed25519 signature of the body, base64, or null when no signing key is set.
     * The collector pins the matching public key.
     */
    public function sign(string $body): ?string
    {
        $key = (string) config('ryvr.ingest.snapshot_signing_key');
        if ($key === '') {
            return null;
        }

        $secret = base64_decode($key, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('RYVR_INGEST_SNAPSHOT_SIGNING_KEY must be a base64 Ed25519 secret key (run php artisan ryvr:ingest:snapshot-key).');
        }

        return base64_encode(sodium_crypto_sign_detached($body, $secret));
    }

    /**
     * Tell listening collectors to refetch. A no-op outside PostgreSQL.
     */
    public static function notifyChanged(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('NOTIFY '.self::CHANNEL);
        }
    }
}
