<?php

use App\Analytics\ClickHouse\ClickHouse;
use Illuminate\Support\Facades\Redis;
use Tests\Fakes\FakeClickHouse;

beforeEach(function () {
    config(['ryvr.health_token' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
    $this->app->instance(ClickHouse::class, new FakeClickHouse);
    $this->redis = Mockery::mock();
    $this->redis->shouldReceive('ping')->andReturn(true)->byDefault();
    $this->redis->shouldReceive('xLen')->with(config('ryvr.ingest.stream'))->andReturn(12)->byDefault();
    Redis::shouldReceive('connection->client')->andReturn($this->redis);
});

it('returns only the public status for anonymous callers', function () {
    $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok'])
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('returns a public error without details when a dependency fails', function () {
    $this->mock(ClickHouse::class)->shouldReceive('ping')->once()->andReturn(false);

    $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'error']);
});

it('returns a public error when the writer backlog is too large', function () {
    $this->redis->shouldReceive('xLen')->andReturn(50_000);

    $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'error']);
});

it('returns a public error when redis is unavailable', function () {
    $this->redis->shouldReceive('ping', 'xLen')->andThrow(new RuntimeException('Connection failed'));

    $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'error']);
});

it('returns details for the configured bearer token', function () {
    config(['ryvr.health_token' => 'health-secret']);

    $this->withToken('health-secret')->getJson('/health')->assertOk()->assertExactJson([
        'status' => 'ok',
        'checks' => ['database' => true, 'clickhouse' => true, 'redis' => true, 'ingest_writer' => true],
        'buffered_events' => 12,
    ]);
});

it('does not return details for an incorrect or unconfigured token', function (?string $configured, string $provided) {
    config(['ryvr.health_token' => $configured]);

    $this->withToken($provided)->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
})->with([
    'incorrect' => ['health-secret', 'wrong-secret'],
    'unset' => [null, 'health-secret'],
    'empty' => ['', 'health-secret'],
    'missing' => ['health-secret', ''],
]);

it('keeps detailed checks available to localhost', function (string $ip) {
    $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/health')->assertOk()
        ->assertJsonPath('checks.database', true)->assertJsonPath('buffered_events', 12);
})->with(['127.0.0.1', '::1']);

it('keeps the detailed failure response for internal callers', function () {
    $this->redis->shouldReceive('xLen')->andReturn(50_000);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->getJson('/health')->assertStatus(503)
        ->assertJsonPath('status', 'degraded')->assertJsonPath('checks.ingest_writer', false)
        ->assertJsonPath('buffered_events', 50_000);
});
