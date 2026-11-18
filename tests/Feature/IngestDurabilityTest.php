<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Console\Commands\IngestWorkCommand;
use App\Ingestion\Queue\RedisStreamQueue;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\Fakes\FakeClickHouse;

/*
 * These need a real Redis (the ClickHouse CI job and the dev stack have one); they skip
 * when Redis is not reachable so the plain unit run stays self-contained.
 */
beforeEach(function () {
    try {
        Redis::connection()->client()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not available.');
    }

    config(['ryvr.ingest.stream' => 'ryvr:test:ingest:'.uniqid()]);
    Redis::connection()->client()->del(RedisStreamQueue::SHED_KEY, RedisStreamQueue::SHED_KEY.':flushing');
    Cache::forget('ryvr:ingest:backlog');
});

afterEach(function () {
    try {
        Redis::connection()->client()->del(config('ryvr.ingest.stream'), RedisStreamQueue::SHED_KEY, RedisStreamQueue::SHED_KEY.':flushing');
    } catch (Throwable) {
        // Redis was never reachable; the test was skipped and left nothing behind.
    }
});

it('sheds and counts new hits past the buffer limit instead of trimming accepted ones', function () {
    config(['ryvr.ingest.max_buffered' => 2]);
    $queue = new RedisStreamQueue;

    foreach (range(1, 2) as $i) {
        $queue->push(['project_id' => 'p1', 'n' => $i]);
        Cache::forget('ryvr:ingest:backlog');
    }
    $queue->push(['project_id' => 'p1', 'n' => 3]);

    $redis = Redis::connection()->client();
    expect($redis->xLen($queue->stream()))->toBe(2)
        ->and((int) $redis->hGet(RedisStreamQueue::SHED_KEY, 'p1|'.now()->utc()->format('Y-m-d H:00:00')))->toBe(1);
});

it('writes batches with a stable deduplication token and flushes shed counts once', function () {
    $fake = new FakeClickHouse;
    app()->instance(ClickHouse::class, $fake);
    $queue = new RedisStreamQueue;
    $queue->push(['project_id' => 'p1', 'props' => []]);
    $queue->drop('p1', 'bot');
    Redis::connection()->client()->hIncrBy(RedisStreamQueue::SHED_KEY, 'p1|2026-11-16', 3);

    $this->artisan('ryvr:ingest:work', ['--once' => true])->assertSuccessful();

    $inserts = collect($fake->queries)->filter(fn ($q) => str_starts_with($q['sql'], 'INSERT'));
    $shed = $inserts->first(fn ($q) => ($q['params']['rows'][0]['reason'] ?? null) === 'shed');
    $events = $inserts->first(fn ($q) => $q['sql'] === 'INSERT INTO events');

    expect($shed['params']['rows'][0]['hits'])->toBe(3)
        ->and($shed['params']['token'])->toStartWith('ryvr:shed:')
        ->and($events['params']['token'])->toMatch('/^ryvr:\d+-\d+:\d+-\d+:2$/')
        ->and(Redis::connection()->client()->exists(RedisStreamQueue::SHED_KEY.':flushing'))->toBe(0)
        ->and(Redis::connection()->client()->xLen($queue->stream()))->toBe(0);
});

it('meters shed counters and keeps a retry token distinct from later identical flushes', function () {
    $org = Organization::create(['name' => 'Team', 'slug' => 'team']);
    $site = Project::create(['organization_id' => $org->id, 'name' => 'Main', 'domain' => 'example.test']);
    $redis = Redis::connection()->client();
    $counter = $site->id.'|2026-11-17 12:00:00';
    $fake = new class extends FakeClickHouse
    {
        public bool $fail = true;

        public function insert(string $table, array $rows, bool $async = true, ?string $deduplicationToken = null): void
        {
            parent::insert($table, $rows, $async, $deduplicationToken);
            if ($table === 'ingest_usage' && $this->fail) {
                $this->fail = false;
                throw new RuntimeException('Interrupted usage write');
            }
        }
    };
    $flush = new ReflectionMethod(IngestWorkCommand::class, 'flushShed');
    $command = new IngestWorkCommand;
    $redis->hIncrBy(RedisStreamQueue::SHED_KEY, $counter, 3);
    expect(fn () => $flush->invoke($command, $fake, $redis))->toThrow(RuntimeException::class);
    expect($redis->exists(RedisStreamQueue::SHED_KEY.':flushing'))->toBe(1);
    $flush->invoke($command, $fake, $redis);
    $redis->hIncrBy(RedisStreamQueue::SHED_KEY, $counter, 3);
    $flush->invoke($command, $fake, $redis);
    expect($fake->queries[1]['params'])->toBe($fake->queries[3]['params'])
        ->and($fake->queries[5]['params']['token'])->not->toBe($fake->queries[3]['params']['token'])
        ->and($fake->queries[5]['params']['rows'][0])->toMatchArray(['organization_id' => $org->id, 'project_id' => $site->id, 'hour' => '2026-11-17 12:00:00', 'accepted' => 0, 'filtered' => 0, 'shed' => 3])
        ->and($redis->exists(RedisStreamQueue::SHED_KEY.':flushing'))->toBe(0);
});
