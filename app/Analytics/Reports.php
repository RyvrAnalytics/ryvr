<?php

namespace App\Analytics;

use App\Models\Project;
use App\Tenancy\Access;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read-only reports for the REST API and MCP tools. Every method takes the caller's Access
 * and only ever queries the projects it allows. Unknown or forbidden sites look the same.
 */
class Reports
{
    public function __construct(private readonly Analytics $analytics) {}

    /**
     * @return list<array{id: string, name: string, domain: string, timezone: string, environment: string}>
     */
    public function sites(Access $access): array
    {
        return array_values($access->projects()->map(fn (Project $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'domain' => $p->domain,
            'timezone' => $p->timezone,
            'environment' => $p->environment,
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $params  period, from, to, filters
     * @return array<string, mixed>
     */
    public function overview(Access $access, string $site, array $params): array
    {
        $project = $this->site($access, $site);
        $query = $this->query([$project->id], $project->timezone, $params);

        return [
            'site' => $this->siteRef($project),
            'range' => $this->range($query),
            'current' => $this->analytics->summary($query),
            'previous' => $this->analytics->summary($query->previous()),
            'live_visitors' => $this->analytics->realtime([$project->id])[$project->id] ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function trend(Access $access, string $site, array $params): array
    {
        $project = $this->site($access, $site);
        $query = $this->query([$project->id], $project->timezone, $params);
        $interval = in_array($params['interval'] ?? null, ['hour', 'day', 'month'], true) ? $params['interval'] : $query->interval();

        return [
            'site' => $this->siteRef($project),
            'range' => $this->range($query),
            'interval' => $interval,
            'points' => $this->analytics->timeseries($query, $interval),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function top(Access $access, string $site, string $dimension, array $params): array
    {
        $project = $this->site($access, $site);
        $query = $this->query([$project->id], $project->timezone, $params);
        $allowed = array_merge(array_keys(Query::DIMENSIONS), Analytics::SESSION_DIMENSIONS);
        if (! in_array($dimension, $allowed, true)) {
            throw new InvalidArgumentException('Unknown dimension. Use one of: '.implode(', ', $allowed).'.');
        }

        return [
            'site' => $this->siteRef($project),
            'range' => $this->range($query),
            'dimension' => $dimension,
            'rows' => $this->analytics->breakdown($query, $dimension, (int) ($params['limit'] ?? 10)),
        ];
    }

    /**
     * Every site side by side (sum-of-sites semantics for totals).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function compare(Access $access, array $params): array
    {
        $projects = $access->projects();
        if (! empty($params['sites']) && is_array($params['sites'])) {
            $projects = $projects->whereIn('id', $params['sites'])->values();
        }

        $query = $this->query(array_values($projects->map(fn (Project $project) => $project->id)->all()), $access->organization->timezone, $params);
        $rows = $this->analytics->perProject($query);

        $sites = $projects->map(fn (Project $p) => $this->siteRef($p) + array_diff_key($rows[$p->id] ?? [], ['sparkline' => 1]))->values()->all();
        usort($sites, fn ($a, $b) => ($b['visitors'] ?? 0) <=> ($a['visitors'] ?? 0));

        return [
            'range' => $this->range($query),
            'note' => 'Visitors are counted per site; totals are a sum of sites, not unique people.',
            'totals' => [
                'visitors' => array_sum(array_column($sites, 'visitors')),
                'pageviews' => array_sum(array_column($sites, 'pageviews')),
                'previous_visitors' => array_sum(array_column($sites, 'previous_visitors')),
            ],
            'sites' => $sites,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function realtime(Access $access): array
    {
        $projects = $access->projects();
        $live = $this->analytics->realtime(array_values($projects->map(fn (Project $project) => $project->id)->all()));

        return [
            'window' => 'last 5 minutes',
            'sites' => $projects->map(fn (Project $p) => $this->siteRef($p) + ['visitors' => $live[$p->id] ?? 0])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function goals(Access $access, string $site, array $params): array
    {
        $project = $this->site($access, $site);
        $query = $this->query([$project->id], $project->timezone, $params);

        return [
            'site' => $this->siteRef($project),
            'range' => $this->range($query),
            'goals' => $this->analytics->goals($query, $project->goals()->orderBy('name')->get()),
            'events' => $this->analytics->breakdown($query, 'event', 50),
        ];
    }

    public function site(Access $access, string $idOrDomain): Project
    {
        $project = Str::isUuid($idOrDomain)
            ? $access->findProject($idOrDomain)
            : $access->projects()->first(fn (Project $p) => strcasecmp($p->domain, Project::normalizeHost($idOrDomain)) === 0);

        if (! $project) {
            throw new InvalidArgumentException('Site not found. Call list_sites (or GET /api/v1/sites) for the sites you can read.');
        }

        return $project;
    }

    /**
     * @param  list<string>  $projectIds
     * @param  array<string, mixed>  $params
     */
    public function query(array $projectIds, string $timezone, array $params): Query
    {
        $period = is_string($params['period'] ?? null) ? $params['period'] : '30d';
        if (! in_array($period, Query::PERIODS, true)) {
            throw new InvalidArgumentException('Unknown period. Use one of: '.implode(', ', Query::PERIODS).'.');
        }

        $filters = [];
        foreach ((array) ($params['filters'] ?? []) as $dimension => $value) {
            if (! isset(Query::DIMENSIONS[$dimension])) {
                throw new InvalidArgumentException("Unknown filter [{$dimension}]. Use one of: ".implode(', ', array_keys(Query::DIMENSIONS)).'.');
            }
            if (is_scalar($value) && (string) $value !== '') {
                $filters[$dimension] = mb_substr((string) $value, 0, 500);
            }
        }

        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        return Query::forPeriod($projectIds, $period, $timezone, $filters, $date($params['from'] ?? null), $date($params['to'] ?? null));
    }

    /**
     * @return array{id: string, name: string, domain: string}
     */
    private function siteRef(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'domain' => $project->domain];
    }

    /**
     * @return array{from: string, to: string, timezone: string, filters: array<string, string>}
     */
    private function range(Query $query): array
    {
        return [
            'from' => $query->from->setTimezone($query->timezone)->toDateString(),
            'to' => $query->to->setTimezone($query->timezone)->subSecond()->toDateString(),
            'timezone' => $query->timezone,
            'filters' => $query->filters,
        ];
    }
}
