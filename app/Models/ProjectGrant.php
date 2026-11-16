<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role on exactly one project or one project group, for a member whose
 * project access is "granted_only".
 *
 * @property int $id
 * @property string $organization_id
 * @property int $user_id
 * @property string|null $project_id
 * @property string|null $project_group_id
 * @property Role $role
 */
class ProjectGrant extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'project_id', 'project_group_id', 'role'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'project_group_id');
    }
}
