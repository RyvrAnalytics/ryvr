<?php

namespace App\Http\Controllers;

use App\Analytics\Analytics;
use App\Analytics\Currents;
use App\Analytics\Query;
use App\Enums\Capability;
use App\Http\Controllers\Concerns\BuildsQueries;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Tenancy\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SiteController extends Controller
{
    use BuildsQueries;

    /**
     * Breakdown panels on the site dashboard, each with its tabs (first tab loads with the page).
     */
    public const PANELS = [
        'pages' => ['page', 'entry_page', 'exit_page'],
        'sources' => ['referrer', 'channel', 'utm_source', 'utm_campaign'],
        'audience' => ['country', 'city', 'device', 'browser', 'os'],
    ];

    /**
     * The portfolio: every site the member can see, side by side.
     */
    public function index(Request $request, Access $access, Analytics $analytics): Response
    {
        $groupId = $request->query('group');
        $groups = ProjectGroup::with('projects:id')->where('organization_id', $access->organization->id)->orderBy('name')->get();
        $projects = $access->projects();

        if (is_string($groupId) && ($group = $groups->firstWhere('id', $groupId))) {
            $projects = $projects->whereIn('id', $group->projects->pluck('id'))->values();
        }

        $query = $this->queryFromRequest($request, array_values($projects->map(fn (Project $project) => $project->id)->all()), $access->organization->timezone);
        $rows = $analytics->perProject($query);
        $live = $analytics->realtime($query->projectIds);

        return Inertia::render('sites/Index', [
            'query' => $this->describeQuery($query),
            'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'count' => $g->projects->count()]),
            'group' => is_string($groupId) ? $groupId : null,
            'sites' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'domain' => $p->domain,
                'environment' => $p->environment,
                'live' => $live[$p->id] ?? 0,
                ...($rows[$p->id] ?? ['visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounce_rate' => 0, 'avg_duration' => 0, 'previous_visitors' => 0, 'sparkline' => []]),
            ])->values(),
            'canCreate' => $access->can(Capability::ManageProjects),
            'currents' => app(Currents::class)->forPortfolio($projects, $rows),
            'days' => $this->sparklineDays($query),
        ]);
    }

    public function create(Access $access): Response
    {
        abort_unless($access->can(Capability::ManageProjects), 403);

        return Inertia::render('sites/Create', [
            'timezone' => $access->organization->timezone,
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function store(Request $request, Access $access): RedirectResponse
    {
        abort_unless($access->can(Capability::ManageProjects), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(https?:\/\/)?[a-z0-9.-]+\.[a-z]{2,}(:\d+)?\/?$/i'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $project = Project::create([
            'organization_id' => $access->organization->id,
            'name' => $data['name'],
            'domain' => Project::normalizeHost($data['domain']),
            'timezone' => $data['timezone'],
        ]);

        AuditLog::record('project.created', $access->organization->id, $project, ['domain' => $project->domain]);

        return redirect()->route('sites.settings', $project)->with('toast', ['type' => 'success', 'message' => 'Site added. Install the tracking code to start collecting.']);
    }

    /**
     * One site's dashboard.
     */
    public function show(Request $request, Access $access, Analytics $analytics, string $project): Response
    {
        $project = $this->authorizedProject($access, $project);
        $query = $this->queryFromRequest($request, [$project->id], $project->timezone);
        $previous = $query->previous();

        $panels = [];
        foreach (self::PANELS as $panel => $dimensions) {
            $panels[$panel] = $analytics->breakdown($query, $dimensions[0], 10);
        }

        $current = $analytics->timeseries($query);
        $before = $analytics->timeseries($previous, $query->interval());

        return Inertia::render('sites/Show', [
            'site' => $this->siteProps($project, $access),
            'query' => $this->describeQuery($query),
            'summary' => $analytics->summary($query),
            'previous' => $analytics->summary($previous),
            'series' => array_map(fn ($row, $i) => $row + ['previous' => $before[$i] ?? null], $current, array_keys($current)),
            'panels' => $panels,
            'panelTabs' => self::PANELS,
            'events' => $analytics->breakdown($query, 'event', 20),
            'goals' => $analytics->goals($query, $project->goals()->orderBy('name')->get()),
            'drops' => $analytics->drops($query),
            'currents' => app(Currents::class)->forQuery($query),
            'live' => $analytics->realtime([$project->id])[$project->id] ?? 0,
        ]);
    }

    /**
     * JSON for a panel tab, an expanded list or an event's properties.
     */
    public function breakdown(Request $request, Access $access, Analytics $analytics, string $project): JsonResponse
    {
        $project = $this->authorizedProject($access, $project);
        $query = $this->queryFromRequest($request, [$project->id], $project->timezone);
        $limit = min(500, max(1, (int) $request->query('limit', 10)));

        if ($request->filled('event')) {
            $event = (string) $request->query('event');
            $property = (string) $request->query('property', '');
            $keys = $analytics->propertyKeys($query, $event);
            $property = in_array($property, $keys, true) ? $property : ($keys[0] ?? '');

            return response()->json([
                'keys' => $keys,
                'property' => $property,
                'rows' => $property === '' ? [] : $analytics->propertyBreakdown($query, $event, $property, $limit),
            ]);
        }

        $dimension = (string) $request->query('dimension');
        $allowed = array_merge(array_keys(Query::DIMENSIONS), ['entry_page', 'exit_page']);
        abort_unless(in_array($dimension, $allowed, true), 422);

        return response()->json(['rows' => $analytics->breakdown($query, $dimension, $limit)]);
    }

    public function realtime(Access $access, Analytics $analytics, string $project): JsonResponse
    {
        $project = $this->authorizedProject($access, $project);

        return response()->json(['live' => $analytics->realtime([$project->id])[$project->id] ?? 0]);
    }

    public function export(Request $request, Access $access, Analytics $analytics, string $project): StreamedResponse
    {
        $project = $this->authorizedProject($access, $project, Capability::ExportData);
        $query = $this->queryFromRequest($request, [$project->id], $project->timezone);
        $rows = $analytics->export($query);

        AuditLog::record('project.exported', $access->organization->id, $project, ['rows' => count($rows), 'from' => $query->from->toDateString()]);

        $filename = sprintf('%s-%s-to-%s.csv', Str::slug($project->name), $query->from->setTimezone($project->timezone)->toDateString(), $query->to->setTimezone($project->timezone)->subSecond()->toDateString());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            $first = true;
            foreach ($rows as $row) {
                if ($first) {
                    fputcsv($out, array_keys($row), escape: '');
                    $first = false;
                }
                // Neutralise spreadsheet formulas in visitor-controlled text.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function siteProps(Project $project, Access $access): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'domain' => $project->domain,
            'timezone' => $project->timezone,
            'canManage' => $access->canOnProject(Capability::ManageProjects, $project),
            'canExport' => $access->canOnProject(Capability::ExportData, $project),
            'canManageGoals' => $access->canOnProject(Capability::ManageGoals, $project),
        ];
    }

    /**
     * The days the portfolio sparklines cover (the last 60 days of the period at most).
     *
     * @return list<string>
     */
    private function sparklineDays(Query $query): array
    {
        $days = [];
        $cursor = $query->from->setTimezone($query->timezone)->startOfDay();
        $end = $query->to->setTimezone($query->timezone);
        for ($guard = 0; $cursor < $end && $guard < 400; $guard++, $cursor = $cursor->addDay()) {
            $days[] = $cursor->format('Y-m-d');
        }

        return array_slice($days, -60);
    }

    private function authorizedProject(Access $access, string $id, Capability $capability = Capability::ViewAnalytics): Project
    {
        $project = Str::isUuid($id) ? $access->findProject($id) : null;

        // Not found and not allowed look the same: no hint that another tenant's project exists.
        abort_unless($project && $access->canOnProject($capability, $project), 404);

        return $project;
    }
}
