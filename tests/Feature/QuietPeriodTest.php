<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Enums\Role;
use App\Models\Goal;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Toronto'));
    $organization = Organization::create(['name' => 'Northwind', 'slug' => 'northwind']);
    $this->project = Project::create(['organization_id' => $organization->id, 'name' => 'Northwind', 'domain' => 'northwind.test', 'timezone' => 'America/Toronto']);
    $this->user = User::factory()->create();
    $organization->addMember($this->user, Role::Owner);
    Cache::put('ryvr:has-data:'.$this->project->id, true);

    $this->clickhouse = new class extends FakeClickHouse
    {
        public bool $currentHasTraffic = false;

        public bool $previousHasTraffic = false;

        public function select(string $sql, array $params = []): array
        {
            $previous = isset($params['from']) && $params['from']->lessThan(CarbonImmutable::parse('2026-11-17', 'America/Toronto'));
            $active = $previous ? $this->previousHasTraffic : $this->currentHasTraffic;
            $this->rows = match (true) {
                str_contains($sql, 'AS bounces') => $active
                    ? [['visitors' => 30, 'visits' => 30, 'pageviews' => 0, 'events' => 30, 'bounces' => 30, 'avg_duration' => 0]] : [],
                str_contains($sql, 'FROM ingest_drops') => [['reason' => 'bot', 'hits' => 7]],
                str_contains($sql, 'GROUP BY bucket') && $active => [['bucket' => $previous ? '2026-11-16 03:00' : '2026-11-17 03:00', 'channel' => 'Direct', 'visitors' => 30, 'visits' => 30, 'pageviews' => 0]],
                str_contains($sql, 'ARRAY JOIN') && $active => [['dimension' => 'event', 'value' => 'signup', 'visitors' => 30, 'visits' => 30, 'pageviews' => 0, 'events' => 30]],
                str_contains($sql, 'GROUP BY value') && $active => [['value' => 'signup', 'visitors' => 30, 'visits' => 30, 'pageviews' => 0, 'events' => 30]],
                default => [],
            };

            return parent::select($sql, $params);
        }
    };
    $this->app->instance(ClickHouse::class, $this->clickhouse);
    $this->app->instance('clickhouse.read', $this->clickhouse);
});

it('skips empty event reads while keeping hourly comparisons, goals and ingestion drops', function () {
    $goal = Goal::create(['organization_id' => $this->project->organization_id, 'project_id' => $this->project->id, 'name' => 'Signup', 'type' => 'event', 'value' => 'signup']);

    $this->actingAs($this->user)->get('/sites/'.$this->project->id.'?period=today')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('setup', null)
            ->where('summary.visitors', 0)
            ->where('previous.visitors', 0)
            ->has('series', 24)
            ->where('series.0.bucket', '2026-11-17 00:00')
            ->where('series.23.previous.bucket', '2026-11-16 23:00')
            ->where('series.3.visitors', 0)
            ->where('series.3.previous.visitors', 0)
            ->where('channels', [])
            ->where('panels.pages', [])
            ->where('panels.sources', [])
            ->where('panels.audience', [])
            ->where('events', [])
            ->where('currents', [])
            ->where('flows.links', [])
            ->where('goals.0.id', $goal->id)
            ->where('goals.0.visitors', 0)
            ->where('goals.0.rate', 0)
            ->where('drops.bot', 7)
        );

    $sql = array_column($this->clickhouse->queries, 'sql');
    expect(implode("\n", $sql))->not->toContain('ARRAY JOIN', 'GROUP BY bucket', 'GROUP BY value', 'GROUP BY channel, entry_page')
        ->and(count(array_filter($sql, fn ($query) => str_contains($query, 'AS bounces'))))->toBe(2);

    foreach ($this->clickhouse->queries as $query) {
        if (isset($query['params']['projects'])) {
            expect($query['params']['projects'])->toBe([$this->project->id]);
        }
    }
});

it('retains falling findings and previous chart data when the current period is empty', function () {
    $this->clickhouse->previousHasTraffic = true;

    $this->actingAs($this->user)->get('/sites/'.$this->project->id.'?period=today')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.visitors', 0)
            ->where('previous.visitors', 30)
            ->where('series.3.visitors', 0)
            ->where('series.3.previous.visitors', 30)
            ->where('currents.0.kind', 'falling')
            ->where('currents.0.dimension', null)
            ->where('currents.1.kind', 'falling')
            ->where('currents.1.value', 'signup')
            ->where('currents.1.current', 0)
            ->where('currents.1.previous', 30)
        );

    $batches = array_values(array_filter($this->clickhouse->queries, fn ($query) => str_contains($query['sql'], 'ARRAY JOIN')));
    expect($batches)->toHaveCount(1)
        ->and($batches[0]['params']['limit'])->toBe(100);
});

it('shows new custom-event-only traffic on the next request after an empty period', function () {
    $url = '/sites/'.$this->project->id.'?period=today';
    $this->actingAs($this->user)->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.visitors', 0)->where('events', []));

    $this->clickhouse->currentHasTraffic = true;
    $this->clickhouse->queries = [];

    $this->get($url)->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.visitors', 30)
            ->where('summary.pageviews', 0)
            ->where('summary.events', 30)
            ->where('events.0.value', 'signup')
            ->where('events.0.events', 30)
            ->where('series.3.visitors', 30)
            ->where('currents.0.kind', 'new')
            ->where('currents.0.value', 'signup')
        );

    $batches = array_values(array_filter($this->clickhouse->queries, fn ($query) => str_contains($query['sql'], 'ARRAY JOIN')));
    expect($batches)->toHaveCount(2)
        ->and(array_column(array_column($batches, 'params'), 'limit'))->toBe([10, 50]);
});
