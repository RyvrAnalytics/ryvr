<?php

namespace App\Audit;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\EmbedKey;
use App\Models\Goal;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\ProjectGroup;

final class ModelEvents
{
    public static function register(): void
    {
        Goal::created(fn (Goal $goal) => self::goal('created', $goal));
        Goal::updated(function (Goal $goal) {
            if ($goal->wasChanged(['name', 'type', 'value'])) {
                self::goal('updated', $goal);
            }
        });
        Goal::deleted(fn (Goal $goal) => self::goal('deleted', $goal));

        ProjectGrant::created(fn (ProjectGrant $grant) => self::grant('created', $grant));
        ProjectGrant::updated(function (ProjectGrant $grant) {
            if ($grant->wasChanged(['user_id', 'project_id', 'project_group_id', 'role'])) {
                self::grant('updated', $grant);
            }
        });
        ProjectGrant::deleted(fn (ProjectGrant $grant) => self::grant('deleted', $grant));

        ProjectGroup::deleting(function (ProjectGroup $group) {
            ProjectGrant::where('organization_id', $group->organization_id)->where('project_group_id', $group->id)->get()->each->delete();
        });
        Project::deleting(function (Project $project) {
            $project->goals()->get()->each->delete();
            ProjectGrant::where('organization_id', $project->organization_id)->where('project_id', $project->id)->get()->each->delete();
        });

        Membership::created(function (Membership $membership) {
            if ($membership->role === Role::Owner) {
                AuditLog::record('owner.granted', $membership->organization_id, $membership);
            }
        });
        Membership::updated(function (Membership $membership) {
            if ($membership->wasChanged('role') && ($membership->role === Role::Owner || $membership->getOriginal('role') === Role::Owner)) {
                AuditLog::record('owner.'.($membership->role === Role::Owner ? 'granted' : 'revoked'), $membership->organization_id, $membership, [
                    'from' => $membership->getOriginal('role')->value, 'to' => $membership->role->value,
                ]);
            }
        });
        Membership::deleted(function (Membership $membership) {
            if ($membership->role === Role::Owner) {
                AuditLog::record('owner.revoked', $membership->organization_id, $membership);
            }
        });

        EmbedKey::updated(function (EmbedKey $key) {
            $changes = array_values(array_intersect(array_keys($key->getChanges()), [
                'name', 'allowed_origins', 'project_ids', 'widgets', 'max_ttl_seconds', 'max_period_days', 'rate_limit_per_minute',
            ]));
            if ($changes !== []) {
                AuditLog::record('embed.key.updated', $key->organization_id, $key, ['changes' => $changes]);
            }
        });
    }

    private static function goal(string $event, Goal $goal): void
    {
        AuditLog::record('goal.'.$event, $goal->organization_id, $goal, ['project_id' => $goal->project_id, 'type' => $goal->type]);
    }

    private static function grant(string $event, ProjectGrant $grant): void
    {
        AuditLog::record('project_grant.'.$event, $grant->organization_id, $grant, [
            'user_id' => $grant->user_id, 'project_id' => $grant->project_id, 'group_id' => $grant->project_group_id, 'role' => $grant->role->value,
        ]);
    }
}
