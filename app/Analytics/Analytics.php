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
    private const REQUEST_MEMO = 'ryvr.analytics.memo';

    /** Breakdowns computed per visit (first and last page of the visit). */
    public const SESSION_DIMENSIONS = ['entry_page', 'exit_page'];

    public function __construct(private readonly ClickHouse $clickhouse) {}

    /**
     * Metered ingestion per site in UTC calendar days or months.
     *
     * @param  list<string>|null  $projectIds  Null includes every site, including removed sites.
     * @return list<array{project_id: string, bucket: string, accepted: int, filtered: int, shed: int, bytes: int}>
     */
    public function usage(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, string $interval = 'day', ?array $projectIds = null): array
    {
        if (! in_array($interval, ['day', 'month'], true)) {
            throw new InvalidArgumentException('Usage interval must be day or month.');
        }
        if ($projectIds === []) {
            return [];
        }

        $bucket = $interval === 'day' ? "formatDateTime(hour, '%Y-%m-%d', 'UTC')" : "formatDateTime(hour, '%Y-%m', 'UTC')";
        $params = ['organization' => $organizationId, 'from' => $from, 'to' => $to];
        $scope = '';
        if ($projectIds !== null) {
            $scope = ' AND project_id IN {projects:Array(UUID)}';
            $params['projects'] = $projectIds;
        }
        $rows = $this->clickhouse->select(
            "SELECT toString(project_id) AS project_id, {$bucket} AS bucket, sum(accepted) AS accepted,"
            .' sum(filtered) AS filtered, sum(shed) AS shed, sum(bytes) AS bytes FROM ingest_usage'
            .' WHERE organization_id = {organization:UUID} AND hour >= {from:DateTime} AND hour < {to:DateTime}'.$scope
            .' GROUP BY project_id, bucket ORDER BY project_id, bucket', $params,
        );

        return array_map(fn ($row) => [
            'project_id' => (string) $row['project_id'], 'bucket' => (string) $row['bucket'],
            'accepted' => (int) $row['accepted'], 'filtered' => (int) $row['filtered'],
            'shed' => (int) $row['shed'], 'bytes' => (int) $row['bytes'],
        ], $rows);
    }

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, int>
     */
    public function monthlyOrganizationUsage(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }
        $from = CarbonImmutable::now('UTC')->startOfMonth();
        $rows = $this->clickhouse->select(
            'SELECT toString(organization_id) AS organization_id, sum(accepted) AS accepted FROM ingest_usage'
            .' WHERE organization_id IN {organizations:Array(UUID)} AND hour >= {from:DateTime} AND hour < {to:DateTime}'
            .' GROUP BY organization_id',
            ['organizations' => $organizationIds, 'from' => $from, 'to' => $from->addMonth()],
        );

        return array_map(intval(...), array_column($rows, 'accepted', 'organization_id'));
    }

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
     * Whether a summary contains accepted traffic, including custom-event-only visits.
     *
     * @param  array{visitors: int, visits: int, pageviews: int, events: int}  $summary
     */
    public static function hasTraffic(array $summary): bool
    {
        return $summary['visitors'] > 0 || $summary['visits'] > 0 || $summary['pageviews'] > 0 || $summary['events'] > 0;
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
        foreach ($query->buckets($interval) as $key) {
            $row = $byBucket[$key] ?? [];
            $series[] = [
                'bucket' => $key,
                'visitors' => (int) ($row['visitors'] ?? 0),
                'visits' => (int) ($row['visits'] ?? 0),
                'pageviews' => (int) ($row['pageviews'] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * The same buckets as timeseries(), split by the channel each visit arrived through.
     * Channels are ranked by visits; each metric list lines up with timeseries() buckets.
     *
     * A visitor who came back through two channels counts once in each, so visitor layers
     * can add up to slightly more than the site's visitor total.
     *
     * @return list<array{name: string, visitors: list<int>, visits: list<int>, pageviews: list<int>}>
     */
    public function channelSeries(Query $query, ?string $interval = null): array
    {
        $interval ??= $query->interval();
        $buckets = array_column($this->timeseries($query, $interval), 'bucket');
        if ($query->projectIds === [] || $buckets === []) {
            return [];
        }

        $rows = $this->remember($query, 'channel-series', [$interval], function () use ($query, $interval) {
            $params = ['tz' => $query->timezone];
            $bucket = match ($interval) {
                'hour' => "formatDateTime(toStartOfHour(toTimeZone(ts, {tz:String})), '%Y-%m-%d %H:00')",
                'day' => "formatDateTime(toDate(ts, {tz:String}), '%Y-%m-%d')",
                default => "formatDateTime(toStartOfMonth(toDate(ts, {tz:String})), '%Y-%m')",
            };

            return $this->clickhouse->select(
                "SELECT {$bucket} AS bucket, channel, uniqIf(visitor_id, name != 'engagement') AS visitors,"
                ." uniqIf(session_id, name != 'engagement') AS visits, countIf(name = 'pageview') AS pageviews"
                .' FROM events WHERE '.$this->where($query, $params).' GROUP BY bucket, channel',
                $params,
            );
        });

        $index = array_flip($buckets);
        $empty = array_fill(0, count($buckets), 0);
        $channels = [];
        foreach ($rows as $row) {
            $name = (string) $row['channel'] !== '' ? (string) $row['channel'] : 'Direct';
            $i = $index[$row['bucket']] ?? null;
            if ($i === null) {
                continue;
            }
            $channels[$name] ??= ['name' => $name, 'visitors' => $empty, 'visits' => $empty, 'pageviews' => $empty];
            foreach (['visitors', 'visits', 'pageviews'] as $metric) {
                $channels[$name][$metric][$i] += (int) $row[$metric];
            }
        }

        $channels = array_values($channels);
        usort($channels, fn ($a, $b) => array_sum($b['visits']) <=> array_sum($a['visits']));

        return $channels;
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
     * Several event dimensions in one scan and network round trip.
     *
     * @param  list<string>  $dimensions
     * @return array<string, list<array{value: string, visitors: int, visits: int, pageviews: int, events: int}>>
     */
    public function breakdowns(Query $query, array $dimensions, int $limit = 50): array
    {
        $dimensions = array_values(array_unique($dimensions));
        sort($dimensions);
        foreach ($dimensions as $dimension) {
            if (! isset(Query::DIMENSIONS[$dimension])) {
                throw new InvalidArgumentException("Unknown dimension [{$dimension}].");
            }
        }
        $empty = array_fill_keys($dimensions, []);
        if ($dimensions === [] || $query->projectIds === []) {
            return $empty;
        }
        $limit = max(1, min($limit, (int) config('ryvr.query.max_breakdown_rows', 500)));

        $rows = $this->remember($query, 'breakdowns', [$dimensions, $limit], function () use ($query, $dimensions, $limit) {
            $labels = [];
            foreach ($dimensions as $dimension) {
                $column = Query::DIMENSIONS[$dimension];
                $labels[] = "('{$dimension}', {$column})";
            }
            $params = ['limit' => $limit];

            return $this->clickhouse->select(
                'SELECT item.1 AS dimension, item.2 AS value, uniq(visitor_id) AS visitors, uniq(session_id) AS visits,'
                ." countIf(name = 'pageview') AS pageviews, countIf(name NOT IN ('pageview', 'engagement')) AS events"
                .' FROM events ARRAY JOIN ['.implode(', ', $labels).'] AS item'
                .' WHERE '.$this->where($query, $params)." AND name != 'engagement'"
                ." AND (item.1 != 'page' OR name = 'pageview') AND (item.1 != 'event' OR name != 'pageview')"
                ." AND (NOT startsWith(item.1, 'utm_') OR item.2 != '')"
                .' GROUP BY dimension, value ORDER BY dimension,'
                ." multiIf(dimension = 'page', pageviews, dimension = 'event', events, visitors) DESC,"
                .' visitors DESC, pageviews DESC, value LIMIT {limit:UInt32} BY dimension',
                $params,
            );
        });
        $result = $empty;
        foreach ($rows as $row) {
            $result[$row['dimension']][] = [
                'value' => (string) $row['value'],
                'visitors' => (int) $row['visitors'],
                'visits' => (int) $row['visits'],
                'pageviews' => (int) $row['pageviews'],
                'events' => (int) $row['events'],
            ];
        }

        return $result;
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
     * One summary row per project, plus a visitor sparkline at the query's interval.
     *
     * @return array<string, array{visitors: int, visits: int, pageviews: int, bounce_rate: float, avg_duration: int, previous_visitors: int, sparkline: list<int>}>
     */
    public function perProject(Query $query): array
    {
        if ($query->projectIds === []) {
            return [];
        }

        $interval = $query->interval();

        return $this->remember($query, 'per-project', [$interval], function () use ($query, $interval) {
            $current = $this->perProjectTotals($query);
            $previous = $this->perProjectTotals($query->previous());

            $params = ['tz' => $query->timezone];
            $bucket = match ($interval) {
                'hour' => "formatDateTime(toStartOfHour(toTimeZone(ts, {tz:String})), '%Y-%m-%d %H:00')",
                'day' => "formatDateTime(toDate(ts, {tz:String}), '%Y-%m-%d')",
                'month' => "formatDateTime(toStartOfMonth(toDate(ts, {tz:String})), '%Y-%m')",
            };
            $rows = $this->clickhouse->select(
                "SELECT toString(project_id) AS project, {$bucket} AS bucket,"
                ." uniqIf(visitor_id, name != 'engagement') AS visitors FROM events WHERE ".$this->where($query, $params)
                .' GROUP BY project, bucket',
                $params,
            );
            $spark = [];
            foreach ($rows as $row) {
                $spark[$row['project']][$row['bucket']] = (int) $row['visitors'];
            }

            $buckets = $query->buckets($interval);

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
                    'sparkline' => array_map(fn ($bucket) => $spark[$id][$bucket] ?? 0, $buckets),
                ];
            }

            return $result;
        });
    }

    /**
     * The last 30 minutes for one site: visitors per minute, and what they are doing now.
     *
     * @return array{online: int, minutes: list<array{minute: string, visitors: int, pageviews: int}>, pages: list<array{value: string, visitors: int}>, sources: list<array{value: string, visitors: int}>, countries: list<array{value: string, visitors: int}>}
     */
    public function now(string $projectId, string $timezone): array
    {
        return Cache::remember('ryvr:now:'.$projectId, 5, function () use ($projectId, $timezone) {
            $params = ['p' => $projectId, 'tz' => $timezone];
            $window = "project_id = {p:UUID} AND ts >= now64(3) - INTERVAL 30 MINUTE AND name != 'engagement'";

            $rows = $this->clickhouse->select(
                "SELECT formatDateTime(toStartOfMinute(toTimeZone(ts, {tz:String})), '%H:%i') AS minute,"
                .' uniq(visitor_id) AS visitors, countIf(name = \'pageview\') AS pageviews'
                ." FROM events WHERE {$window} GROUP BY minute ORDER BY minute",
                $params,
            );
            $byMinute = array_column($rows, null, 'minute');
            $minutes = [];
            $cursor = CarbonImmutable::now($timezone)->startOfMinute()->subMinutes(29);
            for ($i = 0; $i < 30; $i++, $cursor = $cursor->addMinute()) {
                $key = $cursor->format('H:i');
                $minutes[] = ['minute' => $key, 'visitors' => (int) ($byMinute[$key]['visitors'] ?? 0), 'pageviews' => (int) ($byMinute[$key]['pageviews'] ?? 0)];
            }

            $top = fn (string $column, string $scope) => array_map(
                fn ($r) => ['value' => (string) $r['value'], 'visitors' => (int) $r['visitors']],
                $this->clickhouse->select(
                    "SELECT {$column} AS value, uniq(visitor_id) AS visitors FROM events WHERE {$scope}"
                    .' GROUP BY value ORDER BY visitors DESC, value LIMIT 10',
                    $params,
                ),
            );

            $recent = "project_id = {p:UUID} AND ts >= now64(3) - INTERVAL 5 MINUTE AND name != 'engagement'";

            return [
                'online' => $this->realtime([$projectId])[$projectId] ?? 0,
                'minutes' => $minutes,
                'pages' => $top('pathname', $recent." AND name = 'pageview'"),
                'sources' => $top("if(referrer_domain = '', channel, referrer_domain)", $window),
                'countries' => $top('country', $window),
            ];
        });
    }

    /**
     * Whether a site has ever recorded a visit (drives the first-run setup view).
     */
    public function hasReceivedData(string $projectId): bool
    {
        // Once a site has data it always will; only a "yes" is cached for long.
        if (Cache::get('ryvr:has-data:'.$projectId)) {
            return true;
        }

        $has = $this->clickhouse->select(
            "SELECT 1 AS found FROM events WHERE project_id = {p:UUID} AND name != 'engagement' LIMIT 1",
            ['p' => $projectId],
        ) !== [];

        if ($has) {
            Cache::put('ryvr:has-data:'.$projectId, true, 86400);
        }

        return $has;
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

        return array_values($goals->map(fn (Goal $goal, int $i) => [
            'id' => $goal->id,
            'name' => $goal->name,
            'type' => $goal->type,
            'value' => $goal->value,
            'visitors' => (int) ($rows["v{$i}"] ?? 0),
            'events' => (int) ($rows["e{$i}"] ?? 0),
            'rate' => $total ? round((int) ($rows["v{$i}"] ?? 0) / $total * 100, 2) : 0.0,
        ])->all());
    }

    /**
     * How visits arrive: channel to landing page, for the flow chart.
     * The busiest channels and pages keep their names; the rest are grouped as "Other".
     *
     * @return array{nodes: array{sources: list<array{name: string, visits: int}>, pages: list<array{name: string, visits: int}>}, links: list<array{source: string, page: string, visits: int}>}
     */
    public function flows(Query $query, int $sources = 5, int $pages = 6): array
    {
        $empty = ['nodes' => ['sources' => [], 'pages' => []], 'links' => []];
        if ($query->projectIds === []) {
            return $empty;
        }

        $rows = $this->remember($query, 'flows', [], function () use ($query) {
            $params = [];

            return $this->clickhouse->select(
                'SELECT channel, entry_page, count() AS visits FROM ('.$this->sessionsSql($query, $params).')'
                ." WHERE pv > 0 AND entry_page != '' GROUP BY channel, entry_page ORDER BY visits DESC LIMIT 2000",
                $params,
            );
        });

        $bySource = [];
        $byPage = [];
        foreach ($rows as $row) {
            $bySource[(string) $row['channel']] = ($bySource[(string) $row['channel']] ?? 0) + (int) $row['visits'];
            $byPage[(string) $row['entry_page']] = ($byPage[(string) $row['entry_page']] ?? 0) + (int) $row['visits'];
        }
        arsort($bySource);
        arsort($byPage);
        $keepSources = array_slice(array_keys($bySource), 0, $sources);
        $keepPages = array_slice(array_keys($byPage), 0, $pages);

        $links = [];
        foreach ($rows as $row) {
            $source = in_array((string) $row['channel'], $keepSources, true) ? (string) $row['channel'] : 'Other';
            $page = in_array((string) $row['entry_page'], $keepPages, true) ? (string) $row['entry_page'] : 'Other pages';
            $links[$source.'|'.$page] = ($links[$source.'|'.$page] ?? 0) + (int) $row['visits'];
        }

        $sourceTotals = [];
        $pageTotals = [];
        $out = [];
        foreach ($links as $key => $visits) {
            [$source, $page] = explode('|', $key, 2);
            $sourceTotals[$source] = ($sourceTotals[$source] ?? 0) + $visits;
            $pageTotals[$page] = ($pageTotals[$page] ?? 0) + $visits;
            $out[] = ['source' => $source, 'page' => $page, 'visits' => $visits];
        }
        arsort($sourceTotals);
        arsort($pageTotals);
        usort($out, fn ($a, $b) => $b['visits'] <=> $a['visits']);

        return [
            'nodes' => [
                'sources' => array_map(fn ($name, $visits) => ['name' => (string) $name, 'visits' => $visits], array_keys($sourceTotals), array_values($sourceTotals)),
                'pages' => array_map(fn ($name, $visits) => ['name' => (string) $name, 'visits' => $visits], array_keys($pageTotals), array_values($pageTotals)),
            ],
            'links' => $out,
        ];
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
            .' FROM events WHERE '.$this->where($query, $params)." AND name != 'engagement' ORDER BY ts DESC LIMIT {limit:UInt32}",
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
            ." argMinIf(pathname, ts, name = 'pageview') AS entry_page, argMaxIf(pathname, ts, name = 'pageview') AS exit_page,"
            .' any(channel) AS channel'
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
     * @template TResult
     *
     * @param  list<mixed>  $extra
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    private function remember(Query $query, string $kind, array $extra, \Closure $callback): mixed
    {
        $request = request();
        $inRequest = $request->route() !== null;
        $key = $query->cacheKey($kind, ...$extra);
        $memo = $request->attributes->get(self::REQUEST_MEMO, []);
        if ($inRequest && is_array($memo) && array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $live = $query->to->greaterThan(CarbonImmutable::now()->subMinutes(5));
        $ttl = $live ? (int) config('ryvr.query.cache_seconds', 60) : 3600;
        $value = Cache::get($key);
        if ($value === null) {
            $value = $callback();

            // Empty answers are not shared across requests, so first visits appear immediately.
            $empty = $value === [] || (is_array($value) && array_key_exists('visits', $value) && $value['visits'] === 0);
            if (! $empty) {
                Cache::put($key, $value, $ttl);
            }
        }

        if ($inRequest) {
            $memo = $request->attributes->get(self::REQUEST_MEMO, []);
            $memo = is_array($memo) ? $memo : [];
            $memo[$key] = $value;
            $request->attributes->set(self::REQUEST_MEMO, $memo);
        }

        return $value;
    }
}
