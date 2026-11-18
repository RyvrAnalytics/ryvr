<?php

namespace App\Http\Controllers\Embed;

use App\Analytics\Analytics;
use App\Analytics\ClickHouse\ClickHouseException;
use App\Analytics\Query;
use App\Embed\EmbedException;
use App\Embed\EmbedGrant;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateEmbedToken;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only widget data for embeds. Every query is built from the EmbedGrant (token claims
 * intersected with the key and the organization's live projects). The viewer may only narrow
 * it: pick one of the granted sites, one of the allowed periods, and filter on adjustable
 * dimensions. Any other parameter is a 400. Locked filters are applied last and always win.
 */
class EmbedDataController extends Controller
{
    private const MAX_LIMIT = 50;

    public function __construct(private readonly Analytics $analytics) {}

    public function meta(Request $request, EmbedGrant $grant): JsonResponse
    {
        return $this->respond(function () use ($request, $grant) {
            $this->params($request, $grant, []);

            return [
                'key_id' => $grant->key->public_id,
                'sites' => $grant->projects->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'domain' => $p->domain])->values()->all(),
                'widgets' => $grant->widgets,
                'period' => ['default' => $grant->defaultPeriod, 'allowed' => $grant->periods],
                'filters' => ['adjustable' => $grant->adjustable, 'locked' => array_keys($grant->lockedFilters)],
                'origins' => $grant->origins,
                'theme' => $grant->theme,
                'expires_at' => $grant->expiresAt,
            ];
        });
    }

    public function summary(Request $request, EmbedGrant $grant): JsonResponse
    {
        return $this->respond(function () use ($request, $grant) {
            $this->widget($grant, 'kpi');
            $query = $this->query($grant, $this->params($request, $grant, ['site', 'period', 'filters']));

            return [
                'range' => $this->range($query),
                'current' => $this->analytics->summary($query),
                'previous' => $this->analytics->summary($query->previous()),
            ];
        });
    }

    public function trend(Request $request, EmbedGrant $grant): JsonResponse
    {
        return $this->respond(function () use ($request, $grant) {
            $this->widget($grant, 'trend');
            $query = $this->query($grant, $this->params($request, $grant, ['site', 'period', 'filters']));
            $interval = $query->interval();

            return [
                'range' => $this->range($query),
                'interval' => $interval,
                'points' => array_slice($this->analytics->timeseries($query, $interval), 0, 400),
            ];
        });
    }

    public function topPages(Request $request, EmbedGrant $grant): JsonResponse
    {
        return $this->respond(function () use ($request, $grant) {
            $this->widget($grant, 'top_pages');
            $params = $this->params($request, $grant, ['site', 'period', 'filters', 'limit']);
            $query = $this->query($grant, $params);

            return [
                'range' => $this->range($query),
                'dimension' => 'page',
                'rows' => $this->analytics->breakdown($query, 'page', $params['limit']),
            ];
        });
    }

    public function topSources(Request $request, EmbedGrant $grant): JsonResponse
    {
        return $this->respond(function () use ($request, $grant) {
            $this->widget($grant, 'top_sources');
            $params = $this->params($request, $grant, ['site', 'period', 'filters', 'limit', 'by']);
            $query = $this->query($grant, $params);

            return [
                'range' => $this->range($query),
                'dimension' => $params['by'],
                'rows' => $this->analytics->breakdown($query, $params['by'], $params['limit']),
            ];
        });
    }

    public function realtime(Request $request, EmbedGrant $grant): JsonResponse
    {
        return $this->respond(function () use ($request, $grant) {
            $this->widget($grant, 'realtime');
            $params = $this->params($request, $grant, ['site']);
            $ids = $params['site'] !== null ? [$params['site']] : $grant->projectIds();
            sort($ids);

            $live = Cache::remember('embed:rt:'.sha1(implode(',', $ids)), (int) config('ryvr.embed.realtime_cache_seconds', 10), fn () => $this->analytics->realtime($ids));

            return ['window' => 'last 5 minutes', 'visitors' => array_sum($live)];
        });
    }

    private function widget(EmbedGrant $grant, string $widget): void
    {
        if (! $grant->allowsWidget($widget)) {
            throw EmbedException::forbidden('widget_not_allowed', "This token does not allow the {$widget} widget.");
        }
    }

    /**
     * Validate the viewer's query string against the grant. Unknown or out-of-grant values are
     * rejected, never ignored, so a tampered request fails loudly.
     *
     * @param  list<string>  $allowed
     * @return array{site: string|null, period: string|null, filters: array<string, string>, limit: int, by: string}
     */
    private function params(Request $request, EmbedGrant $grant, array $allowed): array
    {
        foreach (array_keys($request->query()) as $name) {
            if (! in_array((string) $name, $allowed, true)) {
                throw EmbedException::badRequest("Unknown parameter [{$name}].");
            }
        }

        $site = $request->query('site');
        if ($site !== null && (! is_string($site) || ! $grant->findProject($site))) {
            throw EmbedException::badRequest('The site is not part of this embed.');
        }

        $period = $request->query('period');
        if ($period !== null && (! is_string($period) || ! in_array($period, $grant->periods, true))) {
            throw EmbedException::badRequest('The period is not allowed. Use one of: '.implode(', ', $grant->periods).'.');
        }

        $filters = [];
        $raw = $request->query('filters', []);
        if (! is_array($raw)) {
            throw EmbedException::badRequest('filters must be given as filters[dimension]=value.');
        }
        foreach ($raw as $dimension => $value) {
            if (! in_array((string) $dimension, $grant->adjustable, true)) {
                throw EmbedException::badRequest("Filtering on [{$dimension}] is not allowed in this embed.");
            }
            if (! is_string($value) || $value === '' || mb_strlen($value) > 500) {
                throw EmbedException::badRequest('Filter values must be non-empty strings of at most 500 characters.');
            }
            $filters[(string) $dimension] = $value;
        }

        $limit = $request->query('limit', '10');
        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT) {
            throw EmbedException::badRequest('limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        $by = $request->query('by', 'channel');
        if (! in_array($by, ['channel', 'referrer'], true)) {
            throw EmbedException::badRequest('by must be channel or referrer.');
        }

        return ['site' => $site, 'period' => $period, 'filters' => $filters, 'limit' => (int) $limit, 'by' => $by];
    }

    /**
     * @param  array{site: string|null, period: string|null, filters: array<string, string>, limit: int, by: string}  $params
     */
    private function query(EmbedGrant $grant, array $params): Query
    {
        $project = $params['site'] !== null ? $grant->findProject($params['site']) : null;
        $ids = $project ? [$project->id] : $grant->projectIds();

        $single = $project ?? ($grant->projects->count() === 1 ? $grant->projects->first() : null);
        $timezone = $grant->timezone ?? $single->timezone ?? $grant->organization()->timezone;

        // Locked filters are merged last, so a viewer filter can never replace them.
        $filters = array_merge($params['filters'], $grant->lockedFilters);

        return Query::forPeriod($ids, $params['period'] ?? $grant->defaultPeriod, $timezone, $filters);
    }

    /**
     * @return array{period: string, from: string, to: string, timezone: string}
     */
    private function range(Query $query): array
    {
        return [
            'period' => $query->period,
            'from' => $query->from->setTimezone($query->timezone)->toDateString(),
            'to' => $query->to->setTimezone($query->timezone)->subSecond()->toDateString(),
            'timezone' => $query->timezone,
        ];
    }

    private function respond(\Closure $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (EmbedException $e) {
            return AuthenticateEmbedToken::error($e->reason, $e->getMessage(), $e->status);
        } catch (ClickHouseException $e) {
            if (! $e->isQueryLimit()) {
                throw $e;
            }

            return AuthenticateEmbedToken::error('query_limit_exceeded', 'This embed query exceeded its resource limit. Try a shorter period or fewer sites.', 503);
        }
    }
}
