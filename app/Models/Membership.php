<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $organization_id
 * @property int $user_id
 * @property Role $role
 * @property string $project_access
 */
class Membership extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected $fillable = ['organization_id', 'user_id', 'role', 'project_access'];

    protected function casts(): array
    {
        return ['role' => Role::class];
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
