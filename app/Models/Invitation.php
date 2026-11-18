<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property Role $role
 * @property string $project_access
 * @property list<string>|null $project_ids
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property-read Organization $organization
 * @property-read User|null $inviter
 */
class Invitation extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'email', 'role', 'project_access', 'project_ids', 'invited_by', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'project_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Invitation, 1: string}
     */
    public static function issue(array $attributes): array
    {
        $plain = Str::random(48);
        $invitation = new self($attributes + ['expires_at' => now()->addDays(7)]);
        $invitation->token_hash = hash('sha256', $plain);
        $invitation->save();

        return [$invitation, $plain];
    }

    public static function findPending(string $plain, bool $lockForUpdate = false): ?self
    {
        $invitation = self::with('organization')->where('token_hash', hash('sha256', $plain))
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->first();

        return $invitation && ! $invitation->accepted_at && $invitation->expires_at->isFuture() ? $invitation : null;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
