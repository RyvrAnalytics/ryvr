<?php

namespace App\Console\Commands;

use App\Analytics\ClickHouse\ClickHouse;
use App\Ingestion\Queue\RedisStreamQueue;
use App\Ingestion\Quotas;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Redis as PhpRedis;
use Throwable;

#[Signature('ryvr:ingest:work {--once : Drain what is buffered, then exit} {--max-time=0 : Exit after this many seconds (0 = run forever)}')]
#[Description('Move buffered events from the Redis stream into ClickHouse')]
class IngestWorkCommand extends Command
{
    private bool $stopping = false;

    public function handle(ClickHouse $clickhouse): int
    {
        /** @var PhpRedis $redis */
        $redis = Redis::connection()->client();
        $stream = (string) config('ryvr.ingest.stream');
        $group = (string) config('ryvr.ingest.consumer_group');
        $batch = (int) config('ryvr.ingest.batch_size');
        $consumer = gethostname().'-'.getmypid();
        $deadline = (int) $this->option('max-time') > 0 ? time() + (int) $this->option('max-time') : null;

        try {
            $redis->xGroup('CREATE', $stream, $group, '0', true);
        } catch (Throwable) {
            // Group already exists.
        }

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        $failures = 0;
        $written = 0;
        // Honour `php artisan queue:restart` (run by deploys) so the writer picks up new code.
        $startedAt = time();

        while (! $this->stopping && ($deadline === null || time() < $deadline)) {
            if ((int) Cache::get('illuminate:queue:restart', 0) > $startedAt) {
                $this->components->info('Restart signal received; exiting so the supervisor starts the new release.');
                break;
            }

            // Entries another worker read but never acknowledged (it crashed) come back after a minute.
            $claimed = $redis->xAutoClaim($stream, $group, $consumer, 60_000, '0-0', $batch);
            $messages = is_array($claimed) && ! empty($claimed[1]) ? $claimed[1] : [];

            if ($messages === []) {
                $read = $redis->xReadGroup($group, $consumer, [$stream => '>'], $batch, $this->option('once') ? 1 : 2000);
                $messages = is_array($read) && $read !== [] ? (array) reset($read) : [];
            }

            if ($messages === []) {
                try {
                    $this->flushShed($clickhouse, $redis);
                } catch (Throwable $e) {
                    Log::warning('ryvr shed counters not flushed yet', ['error' => $e->getMessage()]);
                }

                if ($this->option('once')) {
                    break;
                }

                continue;
            }

            try {
                $this->flushShed($clickhouse, $redis);
                $written += $this->write($clickhouse, $messages);
                $ids = array_keys($messages);
                $redis->xAck($stream, $group, $ids);
                $redis->xDel($stream, $ids);
                $failures = 0;
            } catch (Throwable $e) {
                $failures++;
                Log::warning('ryvr ingest write failed; events stay buffered', ['error' => $e->getMessage(), 'attempt' => $failures]);
                $this->components->warn('Write failed, retrying: '.$e->getMessage());
                usleep(min(30, 2 ** min($failures, 5)) * 1_000_000);
            }
        }

        $this->components->info("Wrote {$written} events.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<string, string>>  $messages
     */
    private function write(ClickHouse $clickhouse, array $messages): int
    {
        $events = [];
        $drops = [];
        $usage = [];
        $projectIds = [];
        foreach ($messages as $fields) {
            $data = json_decode($fields['d'] ?? 'null', true);
            if (is_array($data) && isset($data['project_id'])) {
                $projectIds[] = $data['project_id'];
            }
        }
        $organizations = Project::whereIn('id', array_unique($projectIds))->pluck('organization_id', 'id')->all();

        foreach ($messages as $id => $fields) {
            $data = json_decode($fields['d'] ?? 'null', true);
            if (! is_array($data)) {
                continue;
            }
            // The stream id fixes the metering hour across retries, including delayed events.
            $hour = gmdate('Y-m-d H:00:00', intdiv((int) explode('-', (string) $id)[0], 1000));
            $organization = $data['organization_id'] ?? $organizations[$data['project_id']] ?? null;
            if ($organization !== null) {
                $key = $data['project_id'].'|'.$hour;
                $usage[$key] ??= ['organization_id' => $organization, 'project_id' => $data['project_id'], 'hour' => $hour,
                    'accepted' => 0, 'filtered' => 0, 'shed' => 0, 'bytes' => 0];
                $metric = ($fields['t'] ?? '') !== 'd' ? 'accepted'
                    : (in_array($data['reason'], [RedisStreamQueue::REASON_SHED, Quotas::REASON], true) ? 'shed' : 'filtered');
                $usage[$key][$metric]++;
                $usage[$key]['bytes'] += strlen($fields['d'] ?? '');
            }
            if (($fields['t'] ?? '') === 'd') {
                $key = $data['project_id'].'|'.$data['day'].'|'.$data['reason'];
                $drops[$key] ??= ['project_id' => $data['project_id'], 'day' => $data['day'], 'reason' => $data['reason'], 'hits' => 0];
                $drops[$key]['hits']++;
            } else {
                // An empty props map must reach ClickHouse as {} (JSON decoding turns it into []).
                $data['props'] = (object) ($data['props'] ?? []);
                $events[] = $data;
            }
        }

        // A batch re-read after a crash (XAUTOCLAIM) has the same entry ids, so the same token:
        // ClickHouse ignores the repeat instead of counting those events twice.
        $ids = array_keys($messages);
        $token = 'ryvr:'.reset($ids).':'.end($ids).':'.count($ids);

        $clickhouse->insert('events', $events, async: false, deduplicationToken: $token);
        $clickhouse->insert('ingest_drops', array_values($drops), async: false, deduplicationToken: $token);
        $clickhouse->insert('ingest_usage', array_values($usage), async: false, deduplicationToken: $token);

        return count($events);
    }

    /**
     * Move shed-hit counters from Redis into ingest_drops. The hash is renamed first so
     * new sheds keep counting; a flush interrupted by a crash is retried with the same
     * persisted token, so it is never counted twice.
     */
    private function flushShed(ClickHouse $clickhouse, PhpRedis $redis): void
    {
        Cache::lock('ryvr:ingest:shed-flush', 120)->get(fn () => $this->writeShed($clickhouse, $redis));
    }

    private function writeShed(ClickHouse $clickhouse, PhpRedis $redis): void
    {
        $flushing = RedisStreamQueue::SHED_KEY.':flushing';

        if (! $redis->exists($flushing)) {
            if (! $redis->exists(RedisStreamQueue::SHED_KEY)) {
                return;
            }
            $redis->rename(RedisStreamQueue::SHED_KEY, $flushing);
        }

        // Persist a unique token in the renamed hash so two identical flushes both count.
        $redis->hSetNx($flushing, '__token', (string) Str::uuid7());
        /** @var array<string, string> $counts */
        $counts = (array) $redis->hGetAll($flushing);
        $token = 'ryvr:shed:'.$counts['__token'];
        unset($counts['__token']);
        ksort($counts);

        $rows = [];
        $usage = [];
        $projectIds = array_map(fn ($key) => explode('|', $key, 2)[0], array_keys($counts));
        $organizations = Project::whereIn('id', $projectIds)->pluck('organization_id', 'id')->all();
        foreach ($counts as $key => $hits) {
            [$projectId, $time] = explode('|', (string) $key, 2);
            $hour = CarbonImmutable::parse($time, 'UTC')->startOfHour();
            $rows[] = ['project_id' => $projectId, 'day' => $hour->toDateString(), 'reason' => RedisStreamQueue::REASON_SHED, 'hits' => (int) $hits];
            if (isset($organizations[$projectId])) {
                $usage[] = ['organization_id' => $organizations[$projectId], 'project_id' => $projectId, 'hour' => $hour->format('Y-m-d H:i:s'),
                    'accepted' => 0, 'filtered' => 0, 'shed' => (int) $hits, 'bytes' => 0];
            }
        }

        $clickhouse->insert('ingest_drops', $rows, async: false, deduplicationToken: $token);
        $clickhouse->insert('ingest_usage', $usage, async: false, deduplicationToken: $token);
        $redis->del($flushing);
    }
}
