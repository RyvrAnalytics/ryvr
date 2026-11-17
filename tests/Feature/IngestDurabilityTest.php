<?php

use App\Analytics\ClickHouse\ClickHouse;
use App\Ingestion\Queue\RedisStreamQueue;
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
    Redis::connection()->client()->del(config('ryvr.ingest.stream'), RedisStreamQueue::SHED_KEY, RedisStreamQueue::SHED_KEY.':flushing');
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
        ->and((int) $redis->hGet(RedisStreamQueue::SHED_KEY, 'p1|'.now()->utc()->toDateString()))->toBe(1);
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
