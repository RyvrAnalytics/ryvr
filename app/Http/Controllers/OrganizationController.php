<?php

namespace App\Http\Controllers;

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Capability;
use App\Http\Controllers\Concerns\ManagesExclusionRules;
use App\Models\AuditLog;
use App\Models\ExclusionRule;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Tenancy\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    use ManagesExclusionRules;

    public function edit(Access $access): Response
    {
        $organization = $access->organization;

        return Inertia::render('organization/General', [
            'settings' => [
                'name' => $organization->name,
                'timezone' => $organization->timezone,
                'currency' => $organization->currency,
                'mcp_enabled' => $organization->mcp_enabled,
            ],
            'timezones' => \DateTimeZone::listIdentifiers(),
            'groups' => ProjectGroup::withCount('projects')->where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'archived' => Project::where('organization_id', $organization->id)->whereNotNull('archived_at')->orderBy('name')->get(['id', 'name', 'domain', 'archived_at']),
            'canManage' => $access->can(Capability::ManageOrganization),
            'canManageProjects' => $access->can(Capability::ManageProjects),
        ]);
    }

    public function update(Request $request, Access $access): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageOrganization), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'mcp_enabled' => ['boolean'],
        ]);
        $data['currency'] = strtoupper($data['currency']);

        $access->organization->update($data);
        AuditLog::record('organization.updated', $access->organization->id, $access->organization, ['changes' => array_keys($access->organization->getChanges())]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Organization settings saved.']);
    }

    public function switch(Request $request, string $organization): RedirectResponse
    {
        $target = $request->user()->organizations()->where('organizations.id', $organization)->firstOrFail();
        $request->user()->switchOrganization($target);

        return redirect()->route('sites.index');
    }

    public function destroy(Request $request, Access $access, ClickHouse $clickhouse): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageOrganization), 403);
        $organization = $access->organization;
        $request->validate(['confirm' => ['required', Rule::in([$organization->name])]], ['confirm.in' => 'Type the organization name exactly to confirm.']);

        $clickhouse->statement('DELETE FROM events WHERE organization_id = {o:UUID}', ['o' => $organization->id]);
        $projectIds = $organization->projects()->pluck('id')->all();
        if ($projectIds) {
            $clickhouse->statement('DELETE FROM ingest_drops WHERE project_id IN {p:Array(UUID)}', ['p' => $projectIds]);
        }
        AuditLog::record('organization.deleted', null, $organization, ['name' => $organization->name]);
        $organization->delete();

        return redirect()->route('sites.index');
    }

    public function restoreProject(Access $access, string $project): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageProjects), 403);
        $project = Project::where('organization_id', $access->organization->id)->whereNotNull('archived_at')->findOrFail($project);
        $project->restore();
        AuditLog::record('project.restored', $access->organization->id, $project);

        return back()->with('toast', ['type' => 'success', 'message' => "{$project->name} restored. Tracking resumes with the same key."]);
    }

    public function storeGroup(Request $request, Access $access): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageProjects), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:60',
            Rule::unique('project_groups')->where('organization_id', $access->organization->id)]]);

        $group = ProjectGroup::create(['organization_id' => $access->organization->id, 'name' => $data['name']]);
        AuditLog::record('group.created', $access->organization->id, $group);

        return back()->with('toast', ['type' => 'success', 'message' => 'Group created. Add sites to it from each site\'s settings.']);
    }

    public function destroyGroup(Access $access, string $group): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageProjects), 403);
        $group = ProjectGroup::where('organization_id', $access->organization->id)->findOrFail($group);
        $group->delete();
        AuditLog::record('group.deleted', $access->organization->id, $group, ['name' => $group->name]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Group deleted. Its sites are unchanged.']);
    }

    public function exclusions(Access $access): Response
    {
        return Inertia::render('organization/Exclusions', [
            'rules' => $this->rulesFor($access, null),
            'fields' => $this->fieldOptions(),
            'canManage' => $access->can(Capability::ManageExclusions),
            'clientIp' => request()->ip(),
        ]);
    }

    public function storeRule(Request $request, Access $access): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageExclusions), 403);
        $this->createRule($request, $access, null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Exclusion rule added for every site. It applies to new traffic within five minutes.']);
    }

    public function updateRule(Request $request, Access $access, string $rule): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageExclusions), 403);
        $this->toggleRule($request, $access, ExclusionRule::where('organization_id', $access->organization->id)->whereNull('project_id')->findOrFail($rule));

        return back();
    }

    public function destroyRule(Access $access, string $rule): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageExclusions), 403);
        $this->deleteRule($access, ExclusionRule::where('organization_id', $access->organization->id)->whereNull('project_id')->findOrFail($rule));

        return back()->with('toast', ['type' => 'success', 'message' => 'Exclusion rule removed.']);
    }

    public function audit(Request $request, Access $access): Response
    {
        abort_unless($access->can(Capability::ViewAuditLog), 403);

        $logs = AuditLog::where('organization_id', $access->organization->id)
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $userNames = \App\Models\User::whereIn('id', collect($logs->items())->where('actor_type', 'user')->pluck('actor_id')->filter())->pluck('name', 'id');

        return Inertia::render('organization/Audit', [
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor_type === 'user' ? ($userNames[$log->actor_id] ?? 'Deleted user') : ucfirst(str_replace('_', ' ', $log->actor_type)),
                'subject' => $log->subject_type,
                'metadata' => $log->metadata,
                'ip' => $log->ip_address,
                'at' => $log->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
