<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A personal (ryvr_pat_) or organization (ryvr_org_) API token. Only an HMAC digest
 * of the token is stored; the plain value is shown once, at creation.
 *
 * @property string $id
 * @property string $organization_id
 * @property int|null $user_id
 * @property string $name
 * @property string $type "pat" or "org"
 * @property string $token_hash
 * @property string $last_four
 * @property list<string> $scopes
 * @property list<string>|null $project_ids
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Organization $organization
 * @property-read User|null $user
 */
class ApiKey extends Model
{
    use HasUuids;

    public const SCOPES = ['analytics:read', 'analytics:query_raw', 'projects:write'];

    protected $fillable = ['organization_id', 'user_id', 'name', 'type', 'scopes', 'project_ids', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'project_ids' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a key and return it with its plain token (never stored).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: ApiKey, 1: string}
     */
    public static function issue(array $attributes): array
    {
        $prefix = ($attributes['type'] ?? 'pat') === 'org' ? 'ryvr_org_' : 'ryvr_pat_';
        $plain = $prefix.Str::random(40);

        $key = new self($attributes);
        $key->token_hash = self::digest($plain);
        $key->last_four = substr($plain, -4);
        $key->save();

        return [$key, $plain];
    }

    public static function digest(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    public static function findActive(string $plain): ?self
    {
        if (! preg_match('/^ryvr_(pat|org)_[A-Za-z0-9]{40}$/', $plain)) {
            return null;
        }

        $key = self::with(['organization', 'user'])->where('token_hash', self::digest($plain))->first();

        if (! $key || $key->revoked_at || ($key->expires_at && $key->expires_at->isPast())) {
            return null;
        }

        return $key;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
