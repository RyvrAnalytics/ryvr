<?php

use App\Analytics\Analytics;
use App\Analytics\ClickHouse\ClickHouse;
use App\Analytics\ClickHouse\Migrator;
use App\Analytics\Query;
use App\Console\Commands\SmokeTestCommand;
use App\Enums\Role;
use App\Ingestion\Collector;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\EmbedTokens;

beforeEach(function () {
    config([
        'ryvr.clickhouse.url' => 'http://127.0.0.1:8123',
        'ryvr.clickhouse.database' => 'ryvr',
        'ryvr.clickhouse.username' => 'writer',
        'ryvr.clickhouse.password' => 'write-secret',
        'ryvr.clickhouse.read.username' => 'reader',
        'ryvr.clickhouse.read.password' => 'read-secret',
    ]);

    Http::preventStrayRequests();
});

it('uses the configured read credentials or falls back to the main pair', function (?string $username, ?string $password, string $expectedUsername, string $expectedPassword) {
    config([
        'ryvr.clickhouse.read.username' => $username,
        'ryvr.clickhouse.read.password' => $password,
    ]);
    Http::fake(fn () => Http::response('{"visitors":3,"visits":2,"pageviews":5,"bounces":1,"avg_duration":10,"events":0}'));

    $summary = app(Analytics::class)->summary(Query::forPeriod(['00000000-0000-0000-0000-000000000001'], 'today', 'UTC'));

    expect($summary['visitors'])->toBe(3);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($expectedUsername, $expectedPassword) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->hasHeader('X-ClickHouse-User', $expectedUsername)
            && $request->hasHeader('X-ClickHouse-Key', $expectedPassword)
            && $query['database'] === 'ryvr'
            && $query['readonly'] === '2';
    });
})->with([
    'separate credentials' => ['reader', 'read-secret', 'reader', 'read-secret'],
    'unset credentials' => [null, null, 'writer', 'write-secret'],
    'empty credentials' => ['', '', 'writer', 'write-secret'],
    'password without a username' => [null, 'unused-secret', 'writer', 'write-secret'],
    'read user without a password' => ['reader', null, 'reader', ''],
    'read user with an empty password' => ['reader', '', 'reader', ''],
]);

it('routes data and health requests through the read client', function (string $surface) {
    Http::fake(fn (Request $request) => Http::response(str_contains($request->url(), '/ping') ? 'Ok.' : ''));
    $organization = Organization::create(['name' => 'Example', 'slug' => 'example']);
    $project = Project::create(['organization_id' => $organization->id, 'name' => 'Example', 'domain' => 'example.test']);
    $user = User::factory()->create();
    $organization->addMember($user, Role::Owner);

    if ($surface === 'portfolio' || $surface === 'dashboard') {
        $this->actingAs($user)->get($surface === 'portfolio' ? '/sites' : "/sites/{$project->id}")->assertOk();
    } elseif ($surface === 'api' || $surface === 'mcp') {
        [, $token] = ApiKey::issue(['organization_id' => $organization->id, 'user_id' => $user->id, 'name' => 'Test', 'type' => 'pat', 'scopes' => ['analytics:read']]);

        if ($surface === 'api') {
            $this->withToken($token)->getJson("/api/v1/sites/{$project->id}/overview")->assertOk();
        } else {
            $this->withToken($token)->postJson('/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'get_overview', 'arguments' => ['site' => $project->id]],
            ], ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])
                ->assertOk()->assertJsonMissing(['isError' => true]);
        }
    } elseif ($surface === 'embed') {
        [$key] = EmbedTokens::key($organization);
        $this->withToken(EmbedTokens::token($key, [$project->id]))
            ->getJson("/embed/v1/{$key->public_id}/summary")->assertOk();
    } else {
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->andReturn(true);
        $redis->shouldReceive('xLen')->andReturn(0);
        Redis::shouldReceive('connection->client')->andReturn($redis);

        $this->getJson('/health')->assertOk();
    }

    expect(Http::recorded())->not->toBeEmpty();
    Http::assertNotSent(fn (Request $request) => ! $request->hasHeader('X-ClickHouse-User', 'reader')
        || ! $request->hasHeader('X-ClickHouse-Key', 'read-secret'));
})->with(['portfolio', 'dashboard', 'api', 'mcp', 'embed', 'health']);

it('keeps inserts and statements on the main credentials', function () {
    Http::fake(fn () => Http::response());

    app('clickhouse.read')->select('SELECT 1');
    $writer = app(ClickHouse::class);
    $writer->insert('events', [['name' => 'pageview']]);
    $writer->statement('DELETE FROM events WHERE project_id = {p:UUID}', ['p' => '00000000-0000-0000-0000-000000000001']);

    Http::assertSentCount(3);
    expect(Http::recorded(fn (Request $request) => $request->hasHeader('X-ClickHouse-User', 'writer')
        && $request->hasHeader('X-ClickHouse-Key', 'write-secret')))->toHaveCount(2);
});

it('refuses every write on the read client before sending a request', function (?string $username) {
    config(['ryvr.clickhouse.read.username' => $username]);
    Http::fake();
    $reader = app('clickhouse.read');

    expect(fn () => $reader->statement('CREATE TABLE example (id UInt64) ENGINE = Memory'))
        ->toThrow(LogicException::class, 'The read-only ClickHouse client cannot execute writes.')
        ->and(fn () => $reader->insert('events', [['name' => 'pageview']]))
        ->toThrow(LogicException::class, 'The read-only ClickHouse client cannot execute writes.')
        ->and(fn () => $reader->insert('events', []))
        ->toThrow(LogicException::class, 'The read-only ClickHouse client cannot execute writes.');

    Http::assertNothingSent();
})->with(['configured' => 'reader', 'fallback' => null]);

it('keeps migration reads and writes on the main credentials', function () {
    Http::fake(fn (Request $request) => Http::response(str_contains($request->body(), 'version()') ? '{"v":"25.8"}' : ''));

    expect(app(Migrator::class)->run())->not->toBeEmpty();

    Http::assertSent(fn (Request $request) => str_contains($request->body(), 'SELECT name FROM ryvr_migrations'));
    Http::assertNotSent(fn (Request $request) => ! $request->hasHeader('X-ClickHouse-User', 'writer')
        || ! $request->hasHeader('X-ClickHouse-Key', 'write-secret'));
});

it('keeps demo data and retention writes on the main credentials', function () {
    Http::fake(fn () => Http::response());
    $organization = Organization::create(['name' => 'Example', 'slug' => 'example']);
    $project = Project::create(['organization_id' => $organization->id, 'name' => 'Example', 'domain' => 'example.test', 'settings' => ['retention_months' => 1]]);

    $this->artisan('ryvr:demo', ['project' => $project->id, '--days' => 1, '--visits' => 1, '--seed' => 1])->assertSuccessful();
    $this->artisan('ryvr:prune')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'query=INSERT INTO events'));
    Http::assertSent(fn (Request $request) => str_starts_with($request->body(), 'DELETE FROM events'));
    Http::assertNotSent(fn (Request $request) => ! $request->hasHeader('X-ClickHouse-User', 'writer')
        || ! $request->hasHeader('X-ClickHouse-Key', 'write-secret'));
});

it('keeps smoke test reads and cleanup on the main credentials', function () {
    $this->mock(Collector::class)->shouldReceive('collect')->times(4)->andReturn(Collector::ACCEPTED);
    Artisan::shouldReceive('call')->with('ryvr:ingest:work', ['--once' => true])->once()->andReturn(0);
    Http::fake(fn (Request $request) => Http::response(match (true) {
        str_contains($request->body(), 'FROM ingest_drops') => '{"reason":"monitor","hits":1}',
        str_starts_with($request->body(), 'SELECT') => '{"visitors":1,"visits":1,"pageviews":2,"bounces":0,"avg_duration":10,"events":1}',
        default => '',
    }));

    $command = app(SmokeTestCommand::class);
    $command->setLaravel($this->app);

    expect($command->run(new ArrayInput([]), new BufferedOutput))->toBe(0);

    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request) => ! $request->hasHeader('X-ClickHouse-User', 'writer')
        || ! $request->hasHeader('X-ClickHouse-Key', 'write-secret'));
});
