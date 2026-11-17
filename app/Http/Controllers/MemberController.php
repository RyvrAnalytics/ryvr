<?php

namespace App\Http\Controllers;

use App\Enums\Capability;
use App\Enums\Role;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectGrant;
use App\Models\ProjectGroup;
use App\Tenancy\Access;
use App\Tenancy\Registration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MemberController extends Controller
{
    public function index(Access $access): Response
    {
        abort_unless($access->can(Capability::ManageMembers) || $access->can(Capability::ViewAnalytics), 403);
        $organization = $access->organization;

        $grants = ProjectGrant::where('organization_id', $organization->id)->get()->groupBy('user_id');

        return Inertia::render('organization/Members', [
            'members' => Membership::with('user:id,name,email')
                ->where('organization_id', $organization->id)
                ->get()
                ->sortBy(fn ($m) => [-$m->role->rank(), $m->user->name])
                ->map(fn (Membership $m) => [
                    'id' => $m->id,
                    'user_id' => $m->user_id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                    'role' => $m->role->value,
                    'project_access' => $m->project_access,
                    'grants' => ($grants[$m->user_id] ?? collect())->map(fn ($g) => [
                        'id' => $g->id, 'project_id' => $g->project_id, 'group_id' => $g->project_group_id, 'role' => $g->role->value,
                    ])->values(),
                    'is_you' => $m->user_id === $access->user?->id,
                ])->values(),
            'invitations' => Invitation::where('organization_id', $organization->id)
                ->whereNull('accepted_at')->where('expires_at', '>', now())
                ->orderByDesc('created_at')->get(['id', 'email', 'role', 'project_access', 'expires_at']),
            'projects' => Project::active()->where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'domain']),
            'groups' => ProjectGroup::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases()),
            'canManage' => $access->can(Capability::ManageMembers),
            'canManageOwners' => $access->can(Capability::ManageOwners),
        ]);
    }

    public function invite(Request $request, Access $access): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageMembers), 403);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
            'project_access' => ['required', Rule::in(['all', 'granted_only'])],
            'project_ids' => ['array', 'required_if:project_access,granted_only'],
            'project_ids.*' => ['uuid'],
        ]);

        $role = Role::from($data['role']);
        $this->guardRole($access, $role);
        if ($data['project_access'] === 'granted_only' && ! in_array($role, Role::projectRoles(), true)) {
            throw ValidationException::withMessages(['role' => 'Site-limited members can be Admin, Analyst or Viewer.']);
        }

        $organization = $access->organization;
        $alreadyMember = Membership::where('organization_id', $organization->id)
            ->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [strtolower($data['email'])]))->exists();
        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'This person is already a member.']);
        }

        [$invitation, $token] = Invitation::issue([
            'organization_id' => $organization->id,
            'email' => strtolower($data['email']),
            'role' => $role,
            'project_access' => $data['project_access'],
            'project_ids' => $data['project_access'] === 'granted_only'
                ? Project::where('organization_id', $organization->id)->whereIn('id', $data['project_ids'] ?? [])->pluck('id')->all()
                : null,
            'invited_by' => $access->user?->id,
        ]);

        $link = route('invitations.show', $token);
        $sent = true;
        try {
            Mail::to($invitation->email)->send(new InvitationMail($organization->name, $access->user->name ?? 'A teammate', $link));
        } catch (Throwable) {
            $sent = false;
        }

        AuditLog::record('member.invited', $organization->id, $invitation, ['email' => $invitation->email, 'role' => $role->value]);

        // The link is shown once so it can be shared by hand when mail is not configured.
        return back()->with('invitationLink', $link)->with('toast', [
            'type' => 'success',
            'message' => $sent ? "Invitation sent to {$invitation->email}." : 'Invitation created. Mail is not configured, so share the link below.',
        ]);
    }

    public function revokeInvitation(Access $access, string $invitation): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageMembers), 403);
        $invitation = Invitation::where('organization_id', $access->organization->id)->findOrFail($invitation);
        $invitation->delete();
        AuditLog::record('member.invitation_revoked', $access->organization->id, $invitation, ['email' => $invitation->email]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Invitation revoked.']);
    }

    public function update(Request $request, Access $access, string $membership): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageMembers), 403);
        $membership = Membership::where('organization_id', $access->organization->id)->findOrFail($membership);

        $data = $request->validate([
            'role' => ['required', Rule::enum(Role::class)],
            'project_access' => ['required', Rule::in(['all', 'granted_only'])],
            'grants' => ['array'],
            'grants.*.project_id' => ['nullable', 'uuid'],
            'grants.*.group_id' => ['nullable', 'uuid'],
            'grants.*.role' => ['required', Rule::in(array_map(fn ($r) => $r->value, Role::projectRoles()))],
        ]);

        $role = Role::from($data['role']);
        $this->guardRole($access, $role);
        $this->guardRole($access, $membership->role);
        $this->guardLastOwner($membership, $role);

        DB::transaction(function () use ($membership, $data, $role, $access) {
            $membership->update(['role' => $role, 'project_access' => $data['project_access']]);

            ProjectGrant::where('organization_id', $access->organization->id)->where('user_id', $membership->user_id)->delete();
            if ($data['project_access'] === 'granted_only') {
                $projectIds = Project::where('organization_id', $access->organization->id)->pluck('id')->all();
                $groupIds = ProjectGroup::where('organization_id', $access->organization->id)->pluck('id')->all();
                foreach ($data['grants'] ?? [] as $grant) {
                    $project = $grant['project_id'] ?? null;
                    $group = $grant['group_id'] ?? null;
                    if (($project && in_array($project, $projectIds, true)) xor ($group && in_array($group, $groupIds, true))) {
                        ProjectGrant::create([
                            'organization_id' => $access->organization->id,
                            'user_id' => $membership->user_id,
                            'project_id' => $project,
                            'project_group_id' => $project ? null : $group,
                            'role' => $grant['role'],
                        ]);
                    }
                }
            }
        });

        AuditLog::record('member.updated', $access->organization->id, $membership, ['role' => $role->value, 'project_access' => $data['project_access']]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Member access updated.']);
    }

    public function destroy(Access $access, string $membership): RedirectResponse
    {
        $membership = Membership::where('organization_id', $access->organization->id)->findOrFail($membership);
        $leaving = $membership->user_id === $access->user?->id;

        abort_unless($leaving || $access->can(Capability::ManageMembers), 403);
        if (! $leaving) {
            $this->guardRole($access, $membership->role);
        }
        $this->guardLastOwner($membership, null);

        ProjectGrant::where('organization_id', $access->organization->id)->where('user_id', $membership->user_id)->delete();
        $membership->delete();
        AuditLog::record($leaving ? 'member.left' : 'member.removed', $access->organization->id, $membership, ['user_id' => $membership->user_id]);

        return $leaving
            ? redirect()->route('sites.index')->with('toast', ['type' => 'success', 'message' => "You left {$access->organization->name}."])
            : back()->with('toast', ['type' => 'success', 'message' => 'Member removed.']);
    }

    /**
     * Accept an invitation link. Guests sign up or sign in first and come back here.
     */
    public function showInvitation(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = Invitation::findPending($token);
        if (! $invitation) {
            return Inertia::render('invitations/Invalid');
        }

        $request->session()->put(Registration::SESSION_KEY, $token);
        $user = $request->user();

        if (! $user) {
            return Inertia::render('invitations/Show', [
                'organization' => $invitation->organization->name,
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
            ]);
        }

        if (strcasecmp($user->email, $invitation->email) !== 0) {
            return Inertia::render('invitations/Show', [
                'organization' => $invitation->organization->name,
                'email' => $invitation->email,
                'role' => $invitation->role->label(),
                'mismatch' => $user->email,
            ]);
        }

        Registration::accept($invitation, $user);

        return redirect()->route('sites.index')->with('toast', ['type' => 'success', 'message' => "You joined {$invitation->organization->name}."]);
    }

    /**
     * Only owners can create, change or remove owners.
     */
    private function guardRole(Access $access, Role $role): void
    {
        if ($role === Role::Owner && ! $access->can(Capability::ManageOwners)) {
            abort(403, 'Only owners can manage owners.');
        }
    }

    private function guardLastOwner(Membership $membership, ?Role $newRole): void
    {
        if ($membership->role !== Role::Owner || $newRole === Role::Owner) {
            return;
        }

        $owners = Membership::where('organization_id', $membership->organization_id)->where('role', Role::Owner->value)->count();
        if ($owners <= 1) {
            throw ValidationException::withMessages(['role' => 'An organization needs at least one owner. Make someone else an owner first.']);
        }
    }
}
