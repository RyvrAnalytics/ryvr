<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Toronto'));
    $this->organization = Organization::create(['name' => 'Northwind', 'slug' => 'northwind', 'timezone' => 'America/Toronto']);
    $this->project = Project::create(['organization_id' => $this->organization->id, 'name' => 'Northwind', 'domain' => 'northwind.test', 'timezone' => 'America/Toronto']);
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, Role::Owner);

    $this->clickhouse = new class extends FakeClickHouse
    {
        public string $project = '';

        public string $bucket = '';

        public function select(string $sql, array $params = []): array
        {
            $this->rows = str_contains($sql, 'GROUP BY project, bucket')
                ? [['project' => $this->project, 'bucket' => $this->bucket, 'visitors' => 7]]
                : [];

            return parent::select($sql, $params);
        }
    };
    $this->clickhouse->project = $this->project->id;
    $this->app->instance(ClickHouse::class, $this->clickhouse);
    $this->app->instance('clickhouse.read', $this->clickhouse);
});

it('aligns portfolio charts and sparklines to the selected reporting interval', function (string $period, string $interval, int $count, string $first, string $last, string $populated, int $index) {
    $this->clickhouse->bucket = $populated;
    $sparkline = array_fill(0, $count, 0);
    $sparkline[$index] = 7;

    $this->actingAs($this->user)->get('/sites?period='.$period)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Index')
            ->where('query.interval', $interval)
            ->where('query.timezone', 'America/Toronto')
            ->has('days', $count)
            ->where('days.0', $first)
            ->where('days.'.($count - 1), $last)
            ->where('sites.0.sparkline', $sparkline)
        );

    $queries = array_values(array_filter($this->clickhouse->queries, fn ($query) => str_contains($query['sql'], 'GROUP BY project, bucket')));
    expect($queries)->toHaveCount(1)
        ->and($queries[0]['params']['tz'])->toBe('America/Toronto')
        ->and($queries[0]['params']['projects'])->toBe([$this->project->id]);

    if ($interval === 'hour') {
        expect($queries[0]['sql'])->toContain('toStartOfHour(toTimeZone(ts, {tz:String}))');
    }
})->with([
    'today by hour' => ['today', 'hour', 24, '2026-11-17 00:00', '2026-11-17 23:00', '2026-11-17 03:00', 3],
    'yesterday by hour' => ['yesterday', 'hour', 24, '2026-11-16 00:00', '2026-11-16 23:00', '2026-11-16 03:00', 3],
    'week by day' => ['7d', 'day', 7, '2026-11-11', '2026-11-17', '2026-11-14', 3],
    'year by month' => ['12mo', 'month', 12, '2025-12', '2026-11', '2026-03', 3],
]);

it('keeps individual-site current and previous series aligned by hour', function () {
    $this->actingAs($this->user)->get('/sites/'.$this->project->id.'?period=today')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sites/Show')
            ->where('query.interval', 'hour')
            ->has('series', 24)
            ->where('series.0.bucket', '2026-11-17 00:00')
            ->where('series.0.previous.bucket', '2026-11-16 00:00')
            ->where('series.23.bucket', '2026-11-17 23:00')
            ->where('series.23.previous.bucket', '2026-11-16 23:00')
            ->where('series.3.visitors', 0)
        );
});
