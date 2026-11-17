<?php

namespace App\Models;

use App\Embed\Origins;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An organization credential that signs embed tokens (HS256). The public id (ek_...) is safe
 * to show in HTML; the secret (ryvr_embed_...) is shown once and stored encrypted, because
 * RYVR has to recompute signatures with it. The columns below are ceilings: a token can only
 * narrow them.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $public_id
 * @property string $name
 * @property string $secret
 * @property string $last_four
 * @property string $alg
 * @property list<string> $allowed_origins
 * @property list<string>|null $project_ids
 * @property list<string> $widgets
 * @property int $max_ttl_seconds
 * @property int $max_period_days
 * @property int $rate_limit_per_minute
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property-read Organization $organization
 * @property-read User|null $creator
 */
class EmbedKey extends Model
{
    use HasUuids;

    public const WIDGETS = ['kpi', 'trend', 'top_pages', 'top_sources', 'realtime'];

    public const ID_PREFIX = 'ek_';

    public const SECRET_PREFIX = 'ryvr_embed_';

    public const TTL_CHOICES = [300, 600, 900, 1800, 3600];

    protected $fillable = ['organization_id', 'name', 'allowed_origins', 'project_ids', 'widgets', 'max_ttl_seconds', 'max_period_days', 'rate_limit_per_minute', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'allowed_origins' => 'array',
            'project_ids' => 'array',
            'widgets' => 'array',
            'max_ttl_seconds' => 'integer',
            'max_period_days' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a key and return it with its plain secret (shown once).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: EmbedKey, 1: string}
     */
    public static function issue(array $attributes): array
    {
        // 32 random bytes = 256 bits, base64url without padding (43 characters).
        $plain = self::SECRET_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $key = new self($attributes);
        $key->public_id = self::ID_PREFIX.Str::lower(Str::random(20));
        $key->secret = $plain;
        $key->last_four = substr($plain, -4);
        $key->alg = 'HS256';
        $key->save();

        return [$key, $plain];
    }

    public static function isPublicId(string $value): bool
    {
        return (bool) preg_match('/^ek_[a-z0-9]{20}$/', $value);
    }

    public static function findActive(string $publicId): ?self
    {
        if (! self::isPublicId($publicId)) {
            return null;
        }

        return self::with('organization')->active()->where('public_id', $publicId)->first();
    }

    /**
     * @param  Builder<EmbedKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Whether a browser origin (scheme://host[:port]) is inside this key's allowlist.
     */
    public function allowsOrigin(string $origin): bool
    {
        return Origins::matchesAny($origin, $this->allowed_origins);
    }

    /**
     * The token lifetime this key allows, never above the hard cap.
     */
    public function ttlCap(): int
    {
        return max(1, min($this->max_ttl_seconds, (int) config('ryvr.embed.max_ttl_hard_cap', 3600)));
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
