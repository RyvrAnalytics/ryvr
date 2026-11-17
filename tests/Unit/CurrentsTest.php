<?php

use App\Analytics\Analytics;
use App\Analytics\ClickHouse\ClickHouse;
use App\Analytics\Currents;
use App\Analytics\Query;
use App\Models\Project;

/**
 * Analytics stub that answers from fixed numbers: "now" for the current period and
 * "before" for the previous one.
 */
function currentsWith(array $now, array $before, array $breakdowns = []): Currents
{
    $analytics = new class(new ClickHouse([]), $now, $before, $breakdowns) extends Analytics
    {
        public function __construct(ClickHouse $c, private array $now, private array $before, private array $breakdowns)
        {
            parent::__construct($c);
        }

        private function isPrevious(Query $q): bool
        {
            return $q->to->lessThan(now()->subDays(20));
        }

        public function summary(Query $query): array
        {
            $v = $this->isPrevious($query) ? $this->before : $this->now;

            return ['visitors' => $v['visitors'], 'visits' => $v['visitors'], 'pageviews' => 0, 'bounce_rate' => 0.0, 'avg_duration' => 0, 'views_per_visit' => 0.0, 'events' => 0];
        }

        public function breakdown(Query $query, string $dimension, int $limit = 10): array
        {
            $rows = $this->breakdowns[$dimension][$this->isPrevious($query) ? 'before' : 'now'] ?? [];

            return array_map(fn ($value, $count) => ['value' => (string) $value, 'visitors' => $count, 'visits' => $count, 'pageviews' => $count, 'events' => $count], array_keys($rows), $rows);
        }

        public function timeseries(Query $query, ?string $interval = null): array
        {
            return [];
        }
    };

    return new Currents($analytics);
}

function thirtyDays(): Query
{
    return Query::forPeriod(['00000000-0000-7000-8000-000000000001'], '30d');
}

it('reports new referrers, rising pages and falling channels above the volume floor', function () {
    $currents = currentsWith(['visitors' => 1000], ['visitors' => 1000], [
        'referrer' => ['now' => ['news.ycombinator.com' => 300, 'google.com' => 400], 'before' => ['google.com' => 400]],
        'page' => ['now' => ['/pricing' => 200], 'before' => ['/pricing' => 80]],
        'channel' => ['now' => ['Social' => 30], 'before' => ['Social' => 200]],
    ]);

    $findings = collect($currents->forQuery(thirtyDays()));

    expect($findings->firstWhere('value', 'news.ycombinator.com'))->kind->toBe('new')
        ->and($findings->firstWhere('value', '/pricing'))->kind->toBe('rising')
        ->and($findings->firstWhere('value', 'Social'))->kind->toBe('falling')
        ->and($findings->firstWhere('value', 'google.com'))->toBeNull();
});

it('ignores tiny changes on small sites', function () {
    $currents = currentsWith(['visitors' => 40], ['visitors' => 20], [
        'page' => ['now' => ['/a' => 6], 'before' => ['/a' => 1]],
        'referrer' => ['now' => ['x.com' => 4], 'before' => []],
    ]);

    $kinds = collect($currents->forQuery(thirtyDays()))->pluck('value')->filter()->all();

    expect($kinds)->toBe([]);
});

it('flags sites that went quiet and sites that moved', function () {
    $currents = currentsWith(['visitors' => 0], ['visitors' => 0]);
    $quiet = new Project(['name' => 'Quiet']);
    $quiet->id = 'a';
    $growing = new Project(['name' => 'Growing']);
    $growing->id = 'b';
    $steady = new Project(['name' => 'Steady']);
    $steady->id = 'c';

    $findings = $currents->forPortfolio([$quiet, $growing, $steady], [
        'a' => ['visitors' => 0, 'previous_visitors' => 500],
        'b' => ['visitors' => 900, 'previous_visitors' => 300],
        'c' => ['visitors' => 1000, 'previous_visitors' => 990],
    ]);

    expect(array_column($findings, 'kind'))->toBe(['silent', 'rising'])
        ->and($findings[0]['site']['name'])->toBe('Quiet');
});
