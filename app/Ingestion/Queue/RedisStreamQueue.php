<?php

namespace App\Ingestion\Queue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Buffers events in a Redis stream. The collector stays fast and keeps accepting hits
 * while ClickHouse restarts or is slow; the writer (ryvr:ingest:work) drains the stream.
 *
 * Accepted events are never trimmed away. When the backlog passes `ryvr.ingest.max_buffered`
 * (the writer has been down for a long time) or Redis refuses the write, new hits are shed
 * instead, and every shed hit is counted per project and day so it shows up with the other
 * drops rather than disappearing silently.
 */
class RedisStreamQueue implements IngestQueue
{
    public const REASON_SHED = 'shed';

    /** Hash of "project|hour" => shed hits, flushed by the writer. */
    public const SHED_KEY = 'ryvr:ingest:shed';

    public function push(array $event): void
    {
        $this->add(['t' => 'e', 'd' => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)], (string) $event['project_id']);
    }

    public function drop(string $projectId, string $reason): void
    {
        $this->add(['t' => 'd', 'd' => json_encode(['project_id' => $projectId, 'reason' => $reason, 'day' => now()->utc()->toDateString()], JSON_THROW_ON_ERROR)], $projectId);
    }

    /**
     * Buffered entries, cached for a few seconds so the check costs one cache read per hit.
     */
    public function backlog(): int
    {
        return (int) Cache::remember('ryvr:ingest:backlog', 5, fn () => (int) Redis::connection()->client()->xLen($this->stream()));
    }

    public function stream(): string
    {
        return (string) config('ryvr.ingest.stream');
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function add(array $fields, string $projectId): void
    {
        if ($this->backlog() >= (int) config('ryvr.ingest.max_buffered')) {
            $this->shed($projectId);

            return;
        }

        try {
            Redis::connection()->client()->xAdd($this->stream(), '*', $fields);
        } catch (Throwable $e) {
            report($e);
            $this->shed($projectId);
        }
    }

    private function shed(string $projectId): void
    {
        try {
            Redis::connection()->client()->hIncrBy(self::SHED_KEY, $projectId.'|'.now()->utc()->format('Y-m-d H:00:00'), 1);
        } catch (Throwable) {
            // Redis is unavailable altogether; the hit is lost and the health check is already red.
        }
    }
}
