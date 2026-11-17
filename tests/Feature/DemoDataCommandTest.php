<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Arr;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->clickhouse = new FakeClickHouse;
    $this->app->instance(ClickHouse::class, $this->clickhouse);
    $this->travelTo(now()->setTime(15, 0));

    $organization = Organization::create(['name' => 'Northwind', 'slug' => 'northwind']);
    $this->site = Project::create(['organization_id' => $organization->id, 'name' => 'Northwind', 'domain' => 'northwind.example']);
});

function demoRows(FakeClickHouse $clickhouse): array
{
    $rows = collect($clickhouse->queries)->flatMap(fn (array $q) => $q['params']['rows'] ?? []);
    $clickhouse->queries = [];

    return $rows->map(fn (array $row) => Arr::except($row, ['event_id']))->all();
}

it('writes the same traffic twice for the same seed', function () {
    $args = ['project' => $this->site->id, '--days' => 3, '--visits' => 20, '--seed' => 7];

    $this->artisan('ryvr:demo', $args)->assertSuccessful();
    $first = demoRows($this->clickhouse);
    $this->artisan('ryvr:demo', $args)->assertSuccessful();

    expect($first)->not->toBeEmpty()
        ->and(demoRows($this->clickhouse))->toEqual($first);
});

it('adds visits from the last half hour with --live, none in the future', function () {
    $this->artisan('ryvr:demo', ['project' => $this->site->id, '--days' => 1, '--visits' => 0, '--live' => 10, '--seed' => 1])->assertSuccessful();

    $stamps = array_column(demoRows($this->clickhouse), 'ts');
    $now = now()->utc()->format('Y-m-d H:i:s.v');

    expect($stamps)->not->toBeEmpty();
    foreach ($stamps as $ts) {
        expect($ts <= $now)->toBeTrue()
            ->and($ts >= now()->utc()->subMinutes(26)->format('Y-m-d H:i:s.v'))->toBeTrue();
    }
});
