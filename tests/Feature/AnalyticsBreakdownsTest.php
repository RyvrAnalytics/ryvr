<?php

use App\Analytics\Analytics;
use App\Analytics\Query;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    $this->clickhouse = new FakeClickHouse;
    $this->analytics = new Analytics($this->clickhouse);
    $this->query = Query::forPeriod(['00000000-0000-7000-8000-000000000001'], '30d', 'America/Toronto', ['country' => 'US']);
});

it('reads and separates several dimensions in one tenant-scoped request', function () {
    $this->clickhouse->rows = [
        ['dimension' => 'page', 'value' => '/guide', 'visitors' => '7', 'visits' => '8', 'pageviews' => '11', 'events' => '0'],
        ['dimension' => 'referrer', 'value' => 'search.example', 'visitors' => '4', 'visits' => '5', 'pageviews' => '9', 'events' => '2'],
    ];

    $rows = $this->analytics->breakdowns($this->query, ['referrer', 'page', 'event'], 50);

    expect($rows)->toBe([
        'event' => [],
        'page' => [['value' => '/guide', 'visitors' => 7, 'visits' => 8, 'pageviews' => 11, 'events' => 0]],
        'referrer' => [['value' => 'search.example', 'visitors' => 4, 'visits' => 5, 'pageviews' => 9, 'events' => 2]],
    ])->and($this->clickhouse->queries)->toHaveCount(1)
        ->and($this->clickhouse->queries[0]['params']['projects'])->toBe($this->query->projectIds)
        ->and($this->clickhouse->queries[0]['params']['from'])->toBe($this->query->from)
        ->and($this->clickhouse->queries[0]['params']['to'])->toBe($this->query->to)
        ->and($this->clickhouse->queries[0]['params'])->toContain('US');
});

it('reuses the same cached answer for reordered or repeated dimensions', function () {
    $this->clickhouse->rows = [['dimension' => 'page', 'value' => '/', 'visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'events' => 0]];

    $first = $this->analytics->breakdowns($this->query, ['page', 'country', 'page']);
    $second = $this->analytics->breakdowns($this->query, ['country', 'page']);

    expect($second)->toBe($first)->and($this->clickhouse->queries)->toHaveCount(1);
});

it('does not cache empty scans so first traffic is visible immediately', function () {
    $this->analytics->breakdowns($this->query, ['page']);
    $this->clickhouse->rows = [['dimension' => 'page', 'value' => '/first', 'visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'events' => 0]];

    $rows = $this->analytics->breakdowns($this->query, ['page']);

    expect($rows['page'][0]['value'])->toBe('/first')->and($this->clickhouse->queries)->toHaveCount(2);
});

it('never reads another tenant when no projects are authorized', function () {
    $rows = $this->analytics->breakdowns($this->query->withProjects([]), ['page', 'referrer']);

    expect($rows)->toBe(['page' => [], 'referrer' => []])->and($this->clickhouse->queries)->toBe([]);
});

it('rejects dimensions outside the column allowlist', function (string $dimension) {
    expect(fn () => $this->analytics->breakdowns($this->query, [$dimension]))
        ->toThrow(InvalidArgumentException::class);
    expect($this->clickhouse->queries)->toBe([]);
})->with(['pathname; DROP TABLE events', 'entry_page']);

it('bounds the number of rows separately for every dimension', function (int $limit, int $expected) {
    $this->analytics->breakdowns($this->query, ['page', 'country'], $limit);

    expect($this->clickhouse->queries[0]['params']['limit'])->toBe($expected)
        ->and($this->clickhouse->queries[0]['sql'])->toContain('LIMIT {limit:UInt32} BY dimension');
})->with([[0, 1], [9999, 500]]);

it('reuses empty reads within one page and sees new traffic on the next request', function () {
    $clickhouse = $this->clickhouse;
    $query = $this->query;
    Route::get('/_test/analytics-memo', function () use ($clickhouse, $query) {
        return response()->json([
            'first' => (new Analytics($clickhouse))->breakdown($query, 'page'),
            'second' => (new Analytics($clickhouse))->breakdown($query, 'page'),
        ]);
    });

    $this->getJson('/_test/analytics-memo')->assertOk()->assertExactJson(['first' => [], 'second' => []]);
    expect($this->clickhouse->queries)->toHaveCount(1);

    $clickhouse->rows = [['value' => '/first-visit', 'visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'events' => 0]];
    $this->getJson('/_test/analytics-memo')->assertOk()
        ->assertJsonPath('first.0.value', '/first-visit')
        ->assertJsonPath('second.0.value', '/first-visit');
    expect($this->clickhouse->queries)->toHaveCount(2);
});

it('keeps request-local answers isolated by project scope', function () {
    $clickhouse = $this->clickhouse;
    $query = $this->query;
    Route::get('/_test/analytics-scopes', function () use ($clickhouse, $query) {
        $clickhouse->rows = [['value' => '/first-site', 'visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'events' => 0]];
        $first = (new Analytics($clickhouse))->breakdown($query, 'page');
        $clickhouse->rows = [['value' => '/second-site', 'visitors' => 1, 'visits' => 1, 'pageviews' => 1, 'events' => 0]];
        $second = (new Analytics($clickhouse))->breakdown($query->withProjects(['00000000-0000-7000-8000-000000000002']), 'page');

        return response()->json(compact('first', 'second'));
    });

    $this->getJson('/_test/analytics-scopes')->assertOk()
        ->assertJsonPath('first.0.value', '/first-site')
        ->assertJsonPath('second.0.value', '/second-site');
    expect($this->clickhouse->queries)->toHaveCount(2);
});
