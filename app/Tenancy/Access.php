<?php

namespace App\Tenancy;

use App\Enums\Capability;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * What one principal (a member, or an API key acting for one) may see and do inside one
 * organization. Every analytics read is scoped by projectIds(); nothing else decides access.
 */
final class Access
{
    /** @var array<string, Role>|null project id => effective role */
    private ?array $projectRoles = null;

    private function __construct(
        public readonly Organization $organization,
        public readonly ?User $user,
        private readonly Role $role,
        private readonly string $projectAccess,
        private readonly ?ApiKey $apiKey = null,
    ) {}

    public static function forUser(User $user, Organization $organization): ?self
    {
        /** @var Membership|null $membership */
        $membership = Membership::where('organization_id', $organization->id)->where('user_id', $user->id)->first();

        return $membership ? new self($organization, $user, $membership->role, $membership->project_access) : null;
    }

    public static function forApiKey(ApiKey $key): ?self
    {
        $organization = $key->organization;

        if ($key->type === 'org') {
            return new self($organization, null, Role::Analyst, 'all', $key);
        }

        // A personal token never exceeds what its owner can do today.
        $owner = $key->user ? self::forUser($key->user, $organization) : null;

        return $owner ? new self($organization, $key->user, $owner->role, $owner->projectAccess, $key) : null;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function apiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    /**
     * Organization-level capability (members, keys, organization settings).
     */
    public function can(Capability $capability): bool
    {
        if ($this->apiKey) {
            return in_array($capability, [Capability::ViewAnalytics, Capability::ExportData, Capability::UseApi], true)
                && $this->role->can($capability);
        }

        return $this->role->can($capability);
    }

    /**
     * Capability on one project, which may come from a project or group grant.
     */
    public function canOnProject(Capability $capability, Project $project): bool
    {
        $role = $this->projectRoles()[$project->id] ?? null;

        if (! $role) {
            return false;
        }

        if ($this->apiKey) {
            return in_array($capability, [Capability::ViewAnalytics, Capability::ExportData], true) && $role->can($capability);
        }

        return $role->can($capability);
    }

    /**
     * Ids of every active project this principal may read analytics for.
     *
     * @return list<string>
     */
    public function projectIds(): array
    {
        return array_keys(array_filter(
            $this->projectRoles(),
            fn (Role $role) => $role->can(Capability::ViewAnalytics),
        ));
    }

    /**
     * @return Collection<int, Project>
     */
    public function projects(): Collection
    {
        return Project::active()
            ->where('organization_id', $this->organization->id)
            ->whereIn('id', $this->projectIds())
            ->orderBy('name')
            ->get();
    }

    public function findProject(string $id): ?Project
    {
        if (! in_array($id, $this->projectIds(), true)) {
            return null;
        }

        return Project::where('organization_id', $this->organization->id)->find($id);
    }

    /**
     * @return array<string, Role>
     */
    private function projectRoles(): array
    {
        if ($this->projectRoles !== null) {
            return $this->projectRoles;
        }

        $allIds = Project::active()->where('organization_id', $this->organization->id)->pluck('id')->all();
        $roles = [];

        if ($this->projectAccess === 'all') {
            foreach ($allIds as $id) {
                $roles[$id] = $this->role;
            }
        } elseif ($this->user) {
            $grants = ProjectGrant::with('group.projects:id')
                ->where('organization_id', $this->organization->id)
                ->where('user_id', $this->user->id)
                ->get();

            foreach ($grants as $grant) {
                $ids = $grant->project_id ? [$grant->project_id] : ($grant->group?->projects->pluck('id')->all() ?? []);
                foreach ($ids as $id) {
                    if (in_array($id, $allIds, true) && (! isset($roles[$id]) || $grant->role->atLeast($roles[$id]))) {
                        $roles[$id] = $grant->role;
                    }
                }
            }
        }

        // A key restricted to some projects only sees the overlap.
        if ($this->apiKey?->project_ids) {
            $roles = array_intersect_key($roles, array_flip($this->apiKey->project_ids));
        }

        return $this->projectRoles = $roles;
    }
}
