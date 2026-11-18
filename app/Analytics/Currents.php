<?php

namespace App\Analytics;

use App\Models\Project;

/**
 * "Currents": the few changes worth reading first. Compares the period with the one before
 * and returns ranked, structured findings that the UI (and MCP clients) turn into sentences.
 *
 * A finding is only reported when it clears a volume floor, so small sites are not flooded
 * with "+300%" noise from three visits.
 */
class Currents
{
    /** Dimensions scanned for movers, in reading priority. */
    private const DIMENSIONS = ['referrer', 'page', 'channel', 'utm_campaign', 'country', 'event'];

    private const LIMIT = 6;

    public function __construct(private readonly Analytics $analytics) {}

    /**
     * Findings for one site (or several sites read together).
     *
     * @return list<array<string, mixed>> findings: kind, dimension, value, current, previous, change, metric
     */
    public function forQuery(Query $query): array
    {
        if ($query->projectIds === []) {
            return [];
        }

        $previous = $query->previous();
        $now = $this->analytics->summary($query);
        $before = $this->analytics->summary($previous);
        $currentData = Analytics::hasTraffic($now) ? $query : $query->withProjects([]);
        $previousData = Analytics::hasTraffic($before) ? $previous : $previous->withProjects([]);
        $floor = max(10, (int) round($now['visitors'] * 0.02));
        $findings = [];

        // Overall traffic, when it moved meaningfully.
        $overall = $this->change($now['visitors'], $before['visitors']);
        if ($before['visitors'] >= $floor && $overall !== null && abs($overall) >= 15) {
            $findings[] = $this->finding($overall > 0 ? 'rising' : 'falling', null, null, $now['visitors'], $before['visitors'], 'visitors', 1000);
        }

        $currentBreakdowns = $this->analytics->breakdowns($currentData, self::DIMENSIONS, 50);
        $previousBreakdowns = $this->analytics->breakdowns($previousData, self::DIMENSIONS, 100);
        foreach (self::DIMENSIONS as $dimension) {
            $metric = $dimension === 'event' ? 'events' : ($dimension === 'page' ? 'pageviews' : 'visitors');
            $current = $this->index($currentBreakdowns[$dimension], $metric);
            $past = $this->index($previousBreakdowns[$dimension], $metric);

            foreach ($current as $value => $count) {
                if ($value === '' || ($dimension === 'channel' && $value === 'Direct')) {
                    continue;
                }
                $was = $past[$value] ?? 0;
                if ($was === 0 && $count >= $floor) {
                    $findings[] = $this->finding('new', $dimension, $value, $count, 0, $metric, $count);
                } elseif ($was >= $floor && $count >= $was * 1.5 && $count - $was >= $floor) {
                    $findings[] = $this->finding('rising', $dimension, $value, $count, $was, $metric, $count - $was);
                }
            }

            foreach ($past as $value => $was) {
                $count = $current[$value] ?? 0;
                if ($value !== '' && $was >= $floor * 2 && $count <= $was * 0.6) {
                    $findings[] = $this->finding('falling', $dimension, $value, $count, $was, $metric, $was - $count);
                }
            }
        }

        // The busiest day, when it stands well clear of a typical day.
        if ($query->interval() === 'day') {
            $series = $this->analytics->timeseries($currentData, 'day');
            $values = array_column($series, 'visitors');
            if (count($values) >= 5) {
                $sorted = $values;
                sort($sorted);
                $median = $sorted[intdiv(count($sorted), 2)];
                $peak = max($values);
                if ($median >= 5 && $peak >= $median * 2) {
                    $day = '';
                    foreach ($series as $point) {
                        if ($point['visitors'] === $peak) {
                            $day = $point['bucket'];
                            break;
                        }
                    }
                    $findings[] = $this->finding('spike', 'day', $day, $peak, $median, 'visitors', ($peak - $median) * 2);
                }
            }
        }

        usort($findings, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return array_map(
            fn (array $f) => array_diff_key($f, ['weight' => 1]),
            array_slice($findings, 0, self::LIMIT),
        );
    }

    /**
     * Portfolio findings: which sites moved most, plus the strongest single-site findings.
     *
     * @param  iterable<Project>  $projects
     * @param  array<string, array<string, mixed>>  $perProject  rows from Analytics::perProject
     * @return list<array<string, mixed>>
     */
    public function forPortfolio(iterable $projects, array $perProject): array
    {
        $findings = [];
        foreach ($projects as $project) {
            $row = $perProject[$project->id] ?? null;
            if (! $row) {
                continue;
            }
            $now = (int) $row['visitors'];
            $was = (int) $row['previous_visitors'];
            $change = $this->change($now, $was);
            $floor = 20;

            $site = ['id' => $project->id, 'name' => $project->name, 'domain' => $project->domain];

            if ($was >= $floor && $now === 0) {
                // A site that stopped reporting usually lost its tracking code.
                $findings[] = ['kind' => 'silent', 'site' => $site, 'current' => 0, 'previous' => $was, 'change' => -100.0, 'metric' => 'visitors', 'weight' => $was * 3];
            } elseif ($was === 0 && $now >= $floor) {
                $findings[] = ['kind' => 'new', 'site' => $site, 'current' => $now, 'previous' => 0, 'change' => null, 'metric' => 'visitors', 'weight' => $now];
            } elseif ($change !== null && $was >= $floor && abs($change) >= 20) {
                $findings[] = ['kind' => $change > 0 ? 'rising' : 'falling', 'site' => $site, 'current' => $now, 'previous' => $was, 'change' => round($change, 1), 'metric' => 'visitors', 'weight' => abs($now - $was)];
            }
        }

        usort($findings, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return array_map(fn ($f) => array_diff_key($f, ['weight' => 1]), array_slice($findings, 0, self::LIMIT));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function index(array $rows, string $metric): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['value']] = (int) ($row[$metric] ?? 0);
        }

        return $out;
    }

    private function change(int $now, int $was): ?float
    {
        return $was > 0 ? ($now - $was) / $was * 100 : null;
    }

    /**
     * @return array{kind: string, dimension: string|null, value: string|null, current: int, previous: int, change: float|null, metric: string, weight: int}
     */
    private function finding(string $kind, ?string $dimension, ?string $value, int $current, int $previous, string $metric, int|float $weight): array
    {
        return [
            'kind' => $kind,
            'dimension' => $dimension,
            'value' => $value,
            'current' => $current,
            'previous' => $previous,
            'change' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
            'metric' => $metric,
            'weight' => (int) $weight,
        ];
    }
}
