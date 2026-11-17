<?php

namespace App\Tenancy;

use App\Enums\Capability;
use App\Enums\Role;
use App\Models\ApiKey;
use App\Models\Invitation;
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
    /**
     * Organization capabilities that act on every site at once (organization settings,
     * organization-wide exclusions, the audit log, creating and restoring sites).
     *
     * @var list<Capability>
     */
    private const ORG_WIDE_CAPABILITIES = [
        Capability::ManageProjects,
        Capability::ManageExclusions,
        Capability::ViewAuditLog,
        Capability::ManageOrganization,
        Capability::ManageOwners,
    ];

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
     *
     * Capabilities that act on every site at once (organization settings, organization-wide
     * exclusions, the audit log, creating and restoring sites) need organization-wide access:
     * a site-limited Admin keeps member, key and embed management, bounded by its own sites.
     */
    public function can(Capability $capability): bool
    {
        if ($this->apiKey) {
            return in_array($capability, [Capability::ViewAnalytics, Capability::ExportData, Capability::UseApi], true)
                && $this->role->can($capability);
        }

        if (in_array($capability, self::ORG_WIDE_CAPABILITIES, true) && ! $this->isOrgWide()) {
            return false;
        }

        return $this->role->can($capability);
    }

    /**
     * Whether this principal reaches every site in the organization, including sites added
     * later. Only such a principal may hand out access that is not limited to listed sites.
     */
    public function isOrgWide(): bool
    {
        return $this->projectAccess === 'all' && ! $this->apiKey?->project_ids;
    }

    /**
     * The role this principal holds on one active project, or null without access.
     */
    public function projectRole(string $projectId): ?Role
    {
        return $this->projectRoles()[$projectId] ?? null;
    }

    /**
     * Whether this principal may give someone (or something) $role on one project. Nobody hands
     * out more than they hold themselves.
     */
    public function canGrantProject(string $projectId, Role $role): bool
    {
        if ($this->isOrgWide()) {
            return $this->role->atLeast($role);
        }

        return $this->projectRole($projectId)?->atLeast($role) ?? false;
    }

    /**
     * Whether this principal may give $role on a project group. A group can gain sites later,
     * so a site-limited principal needs a grant on the group itself, at least as strong.
     */
    public function canGrantGroup(string $groupId, Role $role): bool
    {
        if ($this->isOrgWide()) {
            return $this->role->atLeast($role);
        }

        if (! $this->user || $this->apiKey) {
            return false;
        }

        return ProjectGrant::where('organization_id', $this->organization->id)
            ->where('user_id', $this->user->id)
            ->where('project_group_id', $groupId)
            ->get()
            ->contains(fn (ProjectGrant $grant) => $grant->role->atLeast($role));
    }

    /**
     * Whether a key or link limited to $projectIds (null: every site, now and later) stays
     * inside what this principal can read.
     *
     * @param  list<string>|null  $projectIds
     */
    public function covers(?array $projectIds): bool
    {
        if ($this->isOrgWide()) {
            return true;
        }

        if ($projectIds === null || $projectIds === []) {
            return false;
        }

        return array_diff($projectIds, $this->projectIds()) === [];
    }

    /**
     * Whether this principal may manage another member: their access must sit entirely inside
     * this principal's own. Owners are guarded separately (only owners manage owners).
     */
    public function coversMember(Membership $membership): bool
    {
        if ($this->isOrgWide()) {
            return true;
        }

        if ($membership->project_access !== 'granted_only') {
            return false;
        }

        $grants = ProjectGrant::where('organization_id', $this->organization->id)
            ->where('user_id', $membership->user_id)
            ->get();

        return $grants->every(fn (ProjectGrant $grant) => $grant->project_id
            ? $this->canGrantProject($grant->project_id, $grant->role)
            : ($grant->project_group_id !== null && $this->canGrantGroup($grant->project_group_id, $grant->role)));
    }

    /**
     * Whether this principal could have issued an invitation, and so may revoke it.
     */
    public function coversInvitation(Invitation $invitation): bool
    {
        if ($this->isOrgWide()) {
            return true;
        }

        if ($invitation->project_access !== 'granted_only') {
            return false;
        }

        foreach ($invitation->project_ids ?? [] as $projectId) {
            if (! $this->canGrantProject($projectId, $invitation->role)) {
                return false;
            }
        }

        return true;
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
