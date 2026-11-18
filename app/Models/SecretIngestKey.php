<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $project_id
 * @property string $token_hash
 * @property string $last_four
 * @property Carbon|null $revoked_at
 * @property-read Project $project
 */
class SecretIngestKey extends Model
{
    use HasUuids;

    public const PREFIX = 'ryvr_sk_';

    protected $fillable = ['project_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /** @return array{0: self, 1: string} */
    public static function issue(Project $project): array
    {
        $plain = self::PREFIX.Str::random(40);
        $key = new self(['project_id' => $project->id]);
        $key->token_hash = ApiKey::digest($plain);
        $key->last_four = substr($plain, -4);
        $key->save();

        return [$key, $plain];
    }

    public static function findActive(string $plain): ?self
    {
        if (! preg_match('/^ryvr_sk_[A-Za-z0-9]{40}$/', $plain)) {
            return null;
        }

        return self::with('project')->where('token_hash', ApiKey::digest($plain))
            ->whereNull('revoked_at')->whereHas('project', fn ($q) => $q->whereNull('archived_at'))->first();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
