<?php

namespace App\Console\Commands;

use App\Analytics\ClickHouse\ClickHouse;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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

        while (! $this->stopping && ($deadline === null || time() < $deadline)) {
            // Entries another worker read but never acknowledged (it crashed) come back after a minute.
            $claimed = $redis->xAutoClaim($stream, $group, $consumer, 60_000, '0-0', $batch);
            $messages = is_array($claimed) && ! empty($claimed[1]) ? $claimed[1] : [];

            if ($messages === []) {
                $read = $redis->xReadGroup($group, $consumer, [$stream => '>'], $batch, $this->option('once') ? 1 : 2000);
                $messages = is_array($read) && $read !== [] ? (array) reset($read) : [];
            }

            if ($messages === []) {
                if ($this->option('once')) {
                    break;
                }

                continue;
            }

            try {
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

        foreach ($messages as $fields) {
            $data = json_decode($fields['d'] ?? 'null', true);
            if (! is_array($data)) {
                continue;
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

        $clickhouse->insert('events', $events);
        $clickhouse->insert('ingest_drops', array_values($drops));

        return count($events);
    }
}
