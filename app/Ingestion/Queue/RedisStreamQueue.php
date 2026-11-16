<?php

namespace App\Ingestion\Queue;

use Illuminate\Support\Facades\Redis;

/**
 * Buffers events in a Redis stream. The collector stays fast and keeps accepting hits
 * while ClickHouse restarts or is slow; the writer (ryvr:ingest:work) drains the stream.
 */
class RedisStreamQueue implements IngestQueue
{
    /** Upper bound on buffered entries (approximate trim) so a stalled writer cannot exhaust memory. */
    private const MAX_LENGTH = 2_000_000;

    public function push(array $event): void
    {
        Redis::connection()->client()->xAdd(
            $this->stream(),
            '*',
            ['t' => 'e', 'd' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)],
            self::MAX_LENGTH,
            true,
        );
    }

    public function drop(string $projectId, string $reason): void
    {
        Redis::connection()->client()->xAdd(
            $this->stream(),
            '*',
            ['t' => 'd', 'd' => json_encode(['project_id' => $projectId, 'reason' => $reason, 'day' => now()->utc()->toDateString()])],
            self::MAX_LENGTH,
            true,
        );
    }

    public function stream(): string
    {
        return (string) config('ryvr.ingest.stream');
    }
}
