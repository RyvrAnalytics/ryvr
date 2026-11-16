<?php

namespace App\Analytics;

use App\Analytics\ClickHouse\ClickHouse;
use App\Models\Goal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Every analytics read goes through here.
 *
 * Tenant safety: each query starts from Query::$projectIds (which come from the caller's
 * Access) and an empty list returns empty results without touching ClickHouse.
 * Metric semantics: visitor ids are salted per project, so a visitor count across several
 * projects is a *sum of projects*, never a de-duplicated count of people.
 */
class Analytics
{
    /** Breakdowns computed per visit (first and last page of the visit). */
    public const SESSION_DIMENSIONS = ['entry_page', 'exit_page'];

    public function __construct(private readonly ClickHouse $clickhouse) {}

    /**
     * Headline numbers for the period.
     *
     * @return array{visitors: int, visits: int, pageviews: int, bounce_rate: float, avg_duration: int, views_per_visit: float, events: int}
     */
    public function summary(Query $query): array
    {
        $empty = ['visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounce_rate' => 0.0, 'avg_duration' => 0, 'views_per_visit' => 0.0, 'events' => 0];
        if ($query->projectIds === []) {
            return $empty;
        }

        return $this->remember($query, 'summary', [], function () use ($query, $empty) {
            $params = [];
            $sql = 'SELECT uniqIf(visitor_id, interactions > 0) AS visitors, countIf(interactions > 0) AS visits, sum(pv) AS pageviews,'
                .' countIf(interactions = 1) AS bounces, avgIf(duration, interactions > 0) AS avg_duration, sum(ev) AS events'
                .' FROM ('.$this->sessionsSql($query, $params).')';

            $row = $this->clickhouse->select($sql, $params)[0] ?? [];
            $visits = (int) ($row['visits'] ?? 0);

            return [
                'visitors' => (int) ($row['visitors'] ?? 0),
                'visits' => $visits,
                'pageviews' => (int) ($row['pageviews'] ?? 0),
                'bounce_rate' => $visits ? round((int) $row['bounces'] / $visits * 100, 1) : 0.0,
                'avg_duration' => (int) round((float) ($row['avg_duration'] ?? 0)),
                'views_per_visit' => $visits ? round((int) $row['pageviews'] / $visits, 2) : 0.0,
                'events' => (int) ($row['events'] ?? 0),
            ] + $empty;
        });
    }

    /**
     * Visitors, visits and pageviews per time bucket, with empty buckets filled in.
     *
     * @return list<array{bucket: string, visitors: int, visits: int, pageviews: int}>
     */
    public function timeseries(Query $query, ?string $interval = null): array
    {
        $interval ??= $query->interval();
        if (! in_array($interval, ['hour', 'day', 'month'], true)) {
            throw new InvalidArgumentException("Unknown interval [{$interval}].");
        }

        $rows = $query->projectIds === [] ? [] : $this->remember($query, 'timeseries', [$interval], function () use ($query, $interval) {
            $params = ['tz' => $query->timezone];
            $bucket = match ($interval) {
                'hour' => "formatDateTime(toStartOfHour(toTimeZone(ts, {tz:String})), '%Y-%m-%d %H:00')",
                'day' => "formatDateTime(toDate(ts, {tz:String}), '%Y-%m-%d')",
                'month' => "formatDateTime(toStartOfMonth(toDate(ts, {tz:String})), '%Y-%m')",
            };

            return $this->clickhouse->select(
                "SELECT {$bucket} AS bucket, uniqIf(visitor_id, name != 'engagement') AS visitors,"
                ." uniqIf(session_id, name != 'engagement') AS visits, countIf(name = 'pageview') AS pageviews"
                .' FROM events WHERE '.$this->where($query, $params).' GROUP BY bucket ORDER BY bucket',
                $params,
            );
        });

        $byBucket = array_column($rows, null, 'bucket');
        $series = [];
        $format = ['hour' => 'Y-m-d H:00', 'day' => 'Y-m-d', 'month' => 'Y-m'][$interval];
        $cursor = $query->from->setTimezone($query->timezone);
        $cursor = match ($interval) {
            'hour' => $cursor->startOfHour(),
            'day' => $cursor->startOfDay(),
            'month' => $cursor->startOfMonth(),
        };
        $end = $query->to->setTimezone($query->timezone);

        for ($guard = 0; $cursor < $end && $guard < 2000; $guard++) {
            $key = $cursor->format($format);
            $row = $byBucket[$key] ?? [];
            $series[] = [
                'bucket' => $key,
                'visitors' => (int) ($row['visitors'] ?? 0),
                'visits' => (int) ($row['visits'] ?? 0),
                'pageviews' => (int) ($row['pageviews'] ?? 0),
            ];
            $cursor = match ($interval) {
                'hour' => $cursor->addHour(),
                'day' => $cursor->addDay(),
                'month' => $cursor->addMonthNoOverflow(),
            };
        }

        return $series;
    }

    /**
     * Top values of one dimension.
     *
     * @return list<array{value: string, visitors: int, visits: int, pageviews: int, events?: int}>
     */
    public function breakdown(Query $query, string $dimension, int $limit = 10): array
    {
        $limit = max(1, min($limit, (int) config('ryvr.query.max_breakdown_rows', 500)));

        if ($query->projectIds === []) {
            return [];
        }

        if (in_array($dimension, self::SESSION_DIMENSIONS, true)) {
            return $this->sessionBreakdown($query, $dimension, $limit);
        }

        if (! isset(Query::DIMENSIONS[$dimension])) {
            throw new InvalidArgumentException("Unknown dimension [{$dimension}].");
        }

        return $this->remember($query, 'breakdown', [$dimension, $limit], function () use ($query, $dimension, $limit) {
            $params = ['limit' => $limit];
            $column = Query::DIMENSIONS[$dimension];

            [$scope, $order] = match ($dimension) {
                'page' => ["name = 'pageview'", 'pageviews DESC, visitors DESC'],
                'event' => ["name NOT IN ('pageview', 'engagement')", 'events DESC, visitors DESC'],
                default => ["name != 'engagement'", 'visitors DESC, pageviews DESC'],
            };
            if (str_starts_with($dimension, 'utm_')) {
                $scope .= " AND {$column} != ''";
            }

            $rows = $this->clickhouse->select(
                "SELECT {$column} AS value, uniq(visitor_id) AS visitors, uniq(session_id) AS visits,"
                ." countIf(name = 'pageview') AS pageviews, countIf(name NOT IN ('pageview', 'engagement')) AS events"
                .' FROM events WHERE '.$this->where($query, $params)." AND {$scope}"
                ." GROUP BY value ORDER BY {$order}, value LIMIT {limit:UInt32}",
                $params,
            );

            return array_map(fn ($r) => [
                'value' => (string) $r['value'],
                'visitors' => (int) $r['visitors'],
                'visits' => (int) $r['visits'],
                'pageviews' => (int) $r['pageviews'],
                'events' => (int) $r['events'],
            ], $rows);
        });
    }

    /**
     * Values of one custom property for one custom event.
     *
     * @return list<array{value: string, visitors: int, events: int}>
     */
    public function propertyBreakdown(Query $query, string $event, string $property, int $limit = 20): array
    {
        if ($query->projectIds === []) {
            return [];
        }

        return $this->remember($query, 'props', [$event, $property, $limit], function () use ($query, $event, $property, $limit) {
            $params = ['event' => $event, 'prop' => $property, 'limit' => max(1, min($limit, 500))];

            $rows = $this->clickhouse->select(
                'SELECT props[{prop:String}] AS value, uniq(visitor_id) AS visitors, count() AS events FROM events WHERE '
                .$this->where($query, $params).' AND name = {event:String} AND mapContains(props, {prop:String})'
                .' GROUP BY value ORDER BY events DESC, value LIMIT {limit:UInt32}',
                $params,
            );

            return array_map(fn ($r) => ['value' => (string) $r['value'], 'visitors' => (int) $r['visitors'], 'events' => (int) $r['events']], $rows);
        });
    }

    /**
     * Property keys seen on a custom event, most used first.
     *
     * @return list<string>
     */
    public function propertyKeys(Query $query, string $event): array
    {
        if ($query->projectIds === []) {
            return [];
        }

        return $this->remember($query, 'prop-keys', [$event], function () use ($query, $event) {
            $params = ['event' => $event];

            return array_column($this->clickhouse->select(
                'SELECT arrayJoin(mapKeys(props)) AS key, count() AS c FROM events WHERE '.$this->where($query, $params)
                .' AND name = {event:String} GROUP BY key ORDER BY c DESC LIMIT 50',
                $params,
            ), 'key');
        });
    }

    /**
     * One summary row per project, plus a daily visitor sparkline. Powers the portfolio view.
     *
     * @return array<string, array{visitors: int, visits: int, pageviews: int, bounce_rate: float, avg_duration: int, previous_visitors: int, sparkline: list<int>}>
     */
    public function perProject(Query $query): array
    {
        if ($query->projectIds === []) {
            return [];
        }

        return $this->remember($query, 'per-project', [], function () use ($query) {
            $current = $this->perProjectTotals($query);
            $previous = $this->perProjectTotals($query->previous());

            $params = ['tz' => $query->timezone];
            $daily = $this->clickhouse->select(
                "SELECT toString(project_id) AS project, formatDateTime(toDate(ts, {tz:String}), '%Y-%m-%d') AS day,"
                ." uniqIf(visitor_id, name != 'engagement') AS visitors FROM events WHERE ".$this->where($query, $params)
                .' GROUP BY project, day',
                $params,
            );
            $spark = [];
            foreach ($daily as $row) {
                $spark[$row['project']][$row['day']] = (int) $row['visitors'];
            }

            $days = [];
            $cursor = $query->from->setTimezone($query->timezone)->startOfDay();
            $end = $query->to->setTimezone($query->timezone);
            for ($guard = 0; $cursor < $end && $guard < 400; $guard++, $cursor = $cursor->addDay()) {
                $days[] = $cursor->format('Y-m-d');
            }
            // Long ranges: keep the sparkline readable by using the last 60 days.
            $days = array_slice($days, -60);

            $result = [];
            foreach ($query->projectIds as $id) {
                $row = $current[$id] ?? [];
                $result[$id] = [
                    'visitors' => (int) ($row['visitors'] ?? 0),
                    'visits' => (int) ($row['visits'] ?? 0),
                    'pageviews' => (int) ($row['pageviews'] ?? 0),
                    'bounce_rate' => ($row['visits'] ?? 0) ? round($row['bounces'] / $row['visits'] * 100, 1) : 0.0,
                    'avg_duration' => (int) round((float) ($row['avg_duration'] ?? 0)),
                    'previous_visitors' => (int) ($previous[$id]['visitors'] ?? 0),
                    'sparkline' => array_map(fn ($d) => $spark[$id][$d] ?? 0, $days),
                ];
            }

            return $result;
        });
    }

    /**
     * Visitors active in the last five minutes, per project.
     *
     * @param  list<string>  $projectIds
     * @return array<string, int>
     */
    public function realtime(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = Cache::remember('ryvr:rt:'.sha1(implode(',', $projectIds)), 15, fn () => $this->clickhouse->select(
            'SELECT toString(project_id) AS project, uniq(visitor_id) AS visitors FROM events'
            .' WHERE project_id IN {projects:Array(UUID)} AND ts >= now64(3) - INTERVAL 5 MINUTE GROUP BY project',
            ['projects' => $projectIds],
        ));

        return array_map('intval', array_column($rows, 'visitors', 'project'));
    }

    /**
     * Hits filtered out during ingestion, by reason.
     *
     * @return array<string, int>
     */
    public function drops(Query $query): array
    {
        if ($query->projectIds === []) {
            return [];
        }

        return $this->remember($query, 'drops', [], fn () => array_map('intval', array_column($this->clickhouse->select(
            'SELECT reason, sum(hits) AS hits FROM ingest_drops WHERE project_id IN {projects:Array(UUID)}'
            .' AND day >= toDate({from:DateTime64(3)}) AND day < toDate({to:DateTime64(3)}) + 1 GROUP BY reason ORDER BY hits DESC',
            ['projects' => $query->projectIds, 'from' => $query->from, 'to' => $query->to],
        ), 'hits', 'reason')));
    }

    /**
     * Conversions per goal: unique visitors who completed it, and the share of all visitors.
     *
     * @param  iterable<Goal>  $goals
     * @return list<array{id: string, name: string, type: string, value: string, visitors: int, events: int, rate: float}>
     */
    public function goals(Query $query, iterable $goals): array
    {
        $goals = collect($goals)->filter(fn (Goal $g) => in_array($g->project_id, $query->projectIds, true))->values();
        if ($goals->isEmpty()) {
            return [];
        }

        $rows = $this->remember($query, 'goals', [$goals->pluck('id', 'value')->all()], function () use ($query, $goals) {
            $params = [];
            $selects = ["uniqIf(visitor_id, name != 'engagement') AS total"];
            foreach ($goals as $i => $goal) {
                $params["gp{$i}"] = $goal->project_id;
                if ($goal->type === 'event') {
                    $params["g{$i}"] = $goal->value;
                    $match = "project_id = {gp{$i}:UUID} AND name = {g{$i}:String}";
                } else {
                    $params["g{$i}"] = self::likePattern($goal->value);
                    $match = "project_id = {gp{$i}:UUID} AND name = 'pageview' AND pathname LIKE {g{$i}:String}";
                }
                $selects[] = "uniqIf(visitor_id, {$match}) AS v{$i}, countIf({$match}) AS e{$i}";
            }

            return $this->clickhouse->select('SELECT '.implode(', ', $selects).' FROM events WHERE '.$this->where($query, $params), $params)[0] ?? [];
        });

        $total = (int) ($rows['total'] ?? 0);

        return $goals->map(fn (Goal $goal, int $i) => [
            'id' => $goal->id,
            'name' => $goal->name,
            'type' => $goal->type,
            'value' => $goal->value,
            'visitors' => (int) ($rows["v{$i}"] ?? 0),
            'events' => (int) ($rows["e{$i}"] ?? 0),
            'rate' => $total ? round((int) ($rows["v{$i}"] ?? 0) / $total * 100, 2) : 0.0,
        ])->all();
    }

    /**
     * Raw events for export, newest first. Never includes visitor or session ids.
     *
     * @return list<array<string, mixed>>
     */
    public function export(Query $query, int $limit = 100000): array
    {
        if ($query->projectIds === []) {
            return [];
        }

        $params = ['limit' => max(1, min($limit, 1_000_000)), 'tz' => $query->timezone];

        return $this->clickhouse->select(
            "SELECT toString(project_id) AS project_id, formatDateTime(toTimeZone(ts, {tz:String}), '%Y-%m-%d %H:%i:%S') AS time, name,"
            .' hostname, pathname, referrer_domain, channel, utm_source, utm_medium, utm_campaign, country, region, city,'
            .' browser, os, device, language, toJSONString(props) AS props, revenue, currency'
            ." FROM events WHERE ".$this->where($query, $params)." AND name != 'engagement' ORDER BY ts DESC LIMIT {limit:UInt32}",
            $params,
        );
    }

    /**
     * @return list<array{value: string, visitors: int, visits: int, pageviews: int}>
     */
    private function sessionBreakdown(Query $query, string $dimension, int $limit): array
    {
        return $this->remember($query, 'session-breakdown', [$dimension, $limit], function () use ($query, $dimension, $limit) {
            $params = ['limit' => $limit];

            $rows = $this->clickhouse->select(
                "SELECT {$dimension} AS value, uniq(visitor_id) AS visitors, count() AS visits, sum(pv) AS pageviews,"
                .' round(countIf(interactions = 1) / count() * 100, 1) AS bounce_rate'
                .' FROM ('.$this->sessionsSql($query, $params).") WHERE pv > 0 AND {$dimension} != ''"
                .' GROUP BY value ORDER BY visits DESC, value LIMIT {limit:UInt32}',
                $params,
            );

            return array_map(fn ($r) => [
                'value' => (string) $r['value'],
                'visitors' => (int) $r['visitors'],
                'visits' => (int) $r['visits'],
                'pageviews' => (int) $r['pageviews'],
                'bounce_rate' => (float) $r['bounce_rate'],
            ], $rows);
        });
    }

    /**
     * @param  array<string, string>  $current
     * @return array<string, array<string, mixed>>
     */
    private function perProjectTotals(Query $query): array
    {
        $params = [];
        $rows = $this->clickhouse->select(
            'SELECT toString(project_id) AS project, uniqIf(visitor_id, interactions > 0) AS visitors, countIf(interactions > 0) AS visits,'
            .' sum(pv) AS pageviews, countIf(interactions = 1) AS bounces, avgIf(duration, interactions > 0) AS avg_duration'
            .' FROM ('.$this->sessionsSql($query, $params).') GROUP BY project',
            $params,
        );

        return array_column($rows, null, 'project');
    }

    /**
     * One row per visit in the period.
     *
     * @param  array<string, mixed>  $params
     */
    private function sessionsSql(Query $query, array &$params): string
    {
        return 'SELECT project_id, session_id, any(visitor_id) AS visitor_id,'
            ." countIf(name = 'pageview') AS pv, countIf(name NOT IN ('pageview', 'engagement')) AS ev,"
            ." countIf(name != 'engagement') AS interactions,"
            ." greatest(dateDiff('second', min(ts), max(ts)), intDiv(sum(engagement_ms), 1000)) AS duration,"
            ." argMinIf(pathname, ts, name = 'pageview') AS entry_page, argMaxIf(pathname, ts, name = 'pageview') AS exit_page"
            .' FROM events WHERE '.$this->where($query, $params).' GROUP BY project_id, session_id';
    }

    /**
     * The tenant predicate plus filters. Column names come from Query::DIMENSIONS only;
     * every value is a bound parameter.
     *
     * @param  array<string, mixed>  $params
     */
    private function where(Query $query, array &$params): string
    {
        $params['projects'] = $query->projectIds;
        $params['from'] = $query->from;
        $params['to'] = $query->to;

        $sql = 'project_id IN {projects:Array(UUID)} AND ts >= {from:DateTime64(3)} AND ts < {to:DateTime64(3)}';

        $i = 0;
        foreach ($query->filters as $dimension => $value) {
            $column = Query::DIMENSIONS[$dimension];
            $negate = str_starts_with($value, '!');
            $value = $negate ? substr($value, 1) : $value;
            $name = 'f'.$i++;

            if ($dimension === 'page' && str_contains($value, '*')) {
                $params[$name] = self::likePattern($value);
                $sql .= " AND {$column} ".($negate ? 'NOT LIKE' : 'LIKE')." {{$name}:String}";
            } else {
                $params[$name] = $value;
                $sql .= " AND {$column} ".($negate ? '!=' : '=')." {{$name}:String}";
            }
        }

        return $sql;
    }

    /**
     * "/blog/*" => "/blog/%", with LIKE metacharacters in the rest escaped.
     */
    public static function likePattern(string $glob): string
    {
        return str_replace('*', '%', addcslashes($glob, '%_\\'));
    }

    /**
     * Cache query results briefly; ranges that end in the past are cached longer.
     *
     * @param  list<mixed>  $extra
     */
    private function remember(Query $query, string $kind, array $extra, \Closure $callback): mixed
    {
        $live = $query->to->greaterThan(CarbonImmutable::now()->subMinutes(5));
        $ttl = $live ? (int) config('ryvr.query.cache_seconds', 60) : 3600;

        return Cache::remember($query->cacheKey($kind, ...$extra), $ttl, $callback);
    }
}
