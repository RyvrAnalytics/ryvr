<?php

namespace App\Http\Controllers;

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Capability;
use App\Http\Controllers\Concerns\ManagesExclusionRules;
use App\Models\AuditLog;
use App\Models\ExclusionRule;
use App\Models\Goal;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Tenancy\Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SiteSettingsController extends Controller
{
    use ManagesExclusionRules;

    public function edit(Access $access, string $project): Response
    {
        $project = $this->project($access, $project, Capability::ViewAnalytics);
        $canManage = $access->canOnProject(Capability::ManageProjects, $project);

        return Inertia::render('sites/Settings', [
            'site' => [
                'id' => $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'allowed_hostnames' => $project->allowed_hostnames ?? [],
                'timezone' => $project->timezone,
                'environment' => $project->environment,
                'hash_routing' => (bool) $project->setting('hash_routing', false),
                'groups' => $project->groups()->pluck('project_groups.id'),
                // The ingest key is public by design (it ships in page HTML), but only managers see the settings form.
                'ingest_key' => $canManage ? $project->ingest_key : null,
            ],
            'groups' => ProjectGroup::where('organization_id', $access->organization->id)->orderBy('name')->get(['id', 'name']),
            'scriptUrl' => Project::trackerUrl(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'rules' => $this->rulesFor($access, $project),
            'orgRules' => $this->rulesFor($access, null),
            'goals' => $project->goals()->orderBy('name')->get(['id', 'name', 'type', 'value']),
            'fields' => $this->fieldOptions(),
            'canManage' => $canManage,
            'canManageGoals' => $access->canOnProject(Capability::ManageGoals, $project),
            'canManageExclusions' => $access->canOnProject(Capability::ManageExclusions, $project),
            'clientIp' => request()->ip(),
        ]);
    }

    public function update(Request $request, Access $access, string $project): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageProjects);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(:\d+)?\/?$/i'],
            'allowed_hostnames' => ['array', 'max:50'],
            'allowed_hostnames.*' => ['string', 'max:255', 'regex:/^(\*\.)?[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'timezone' => ['required', 'timezone:all'],
            'environment' => ['required', Rule::in(['production', 'staging', 'development'])],
            'hash_routing' => ['boolean'],
            'groups' => ['array'],
            'groups.*' => ['uuid'],
        ]);

        $project->fill([
            'name' => $data['name'],
            'domain' => Project::normalizeHost($data['domain']),
            'allowed_hostnames' => array_values(array_unique(array_map([Project::class, 'normalizeHost'], $data['allowed_hostnames'] ?? []))),
            'timezone' => $data['timezone'],
            'environment' => $data['environment'],
            'settings' => array_merge($project->settings ?? [], ['hash_routing' => (bool) ($data['hash_routing'] ?? false)]),
        ])->save();

        $groupIds = ProjectGroup::where('organization_id', $access->organization->id)->whereIn('id', $data['groups'] ?? [])->pluck('id');
        $project->groups()->sync($groupIds);

        AuditLog::record('project.updated', $access->organization->id, $project, ['changes' => array_keys($project->getChanges())]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Site settings saved.']);
    }

    public function rotateKey(Access $access, string $project): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageProjects);
        $project->rotateIngestKey();
        AuditLog::record('project.ingest_key_rotated', $access->organization->id, $project);

        return back()->with('toast', ['type' => 'success', 'message' => 'New tracking key created. Update the snippet on your site; the old key no longer works.']);
    }

    public function archive(Access $access, string $project): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageProjects);
        $project->archive();
        AuditLog::record('project.archived', $access->organization->id, $project);

        return redirect()->route('sites.index')->with('toast', ['type' => 'success', 'message' => "{$project->name} archived. Its data is kept; tracking stopped."]);
    }

    public function destroy(Request $request, Access $access, ClickHouse $clickhouse, string $project): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageProjects);
        $request->validate(['confirm' => ['required', 'string', Rule::in([$project->domain])]], [
            'confirm.in' => 'Type the domain exactly to confirm.',
        ]);

        $clickhouse->statement('DELETE FROM events WHERE project_id = {p:UUID}', ['p' => $project->id]);
        $clickhouse->statement('DELETE FROM ingest_drops WHERE project_id = {p:UUID}', ['p' => $project->id]);
        AuditLog::record('project.deleted', $access->organization->id, $project, ['domain' => $project->domain]);
        $project->delete();

        return redirect()->route('sites.index')->with('toast', ['type' => 'success', 'message' => 'Site and all of its data deleted.']);
    }

    public function storeGoal(Request $request, Access $access, string $project): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageGoals);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['pageview', 'event'])],
            'value' => ['required', 'string', 'max:500', Rule::when($request->input('type') === 'pageview', ['starts_with:/'])],
        ]);

        $goal = $project->goals()->create($data + ['organization_id' => $project->organization_id]);
        AuditLog::record('goal.created', $access->organization->id, $goal, $data);

        return back()->with('toast', ['type' => 'success', 'message' => 'Goal added.']);
    }

    public function destroyGoal(Access $access, string $project, string $goal): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageGoals);
        $goal = Goal::where('project_id', $project->id)->findOrFail($goal);
        $goal->delete();
        AuditLog::record('goal.deleted', $access->organization->id, $goal);

        return back()->with('toast', ['type' => 'success', 'message' => 'Goal removed.']);
    }

    public function storeRule(Request $request, Access $access, string $project): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageExclusions);
        $this->createRule($request, $access, $project);

        return back()->with('toast', ['type' => 'success', 'message' => 'Exclusion rule added. It applies to new traffic within five minutes.']);
    }

    public function updateRule(Request $request, Access $access, string $project, string $rule): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageExclusions);
        $this->toggleRule($request, $access, ExclusionRule::where('project_id', $project->id)->findOrFail($rule));

        return back();
    }

    public function destroyRule(Access $access, string $project, string $rule): RedirectResponse
    {
        $project = $this->project($access, $project, Capability::ManageExclusions);
        $this->deleteRule($access, ExclusionRule::where('project_id', $project->id)->findOrFail($rule));

        return back()->with('toast', ['type' => 'success', 'message' => 'Exclusion rule removed.']);
    }

    private function project(Access $access, string $id, Capability $capability): Project
    {
        $project = Str::isUuid($id) ? $access->findProject($id) : null;
        abort_unless($project && $access->canOnProject($capability, $project), 404);

        return $project;
    }
}
