<?php

namespace App\Http\Controllers;

use App\Analytics\ClickHouse\ClickHouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * GET /health: dependency checks for load balancers and uptime monitors.
 * Returns 503 when a store is down or the ingest buffer is backing up.
 */
class HealthController
{
    public function __invoke(ClickHouse $clickhouse): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1') !== []),
            'clickhouse' => $this->check(fn () => $clickhouse->ping()),
            'redis' => $this->check(fn () => (bool) Redis::connection()->client()->ping()),
        ];

        $buffered = null;
        try {
            $buffered = (int) Redis::connection()->client()->xLen((string) config('ryvr.ingest.stream'));
        } catch (Throwable) {
            // Reported by the redis check.
        }

        // A large backlog means the writer is down or ClickHouse is refusing inserts.
        $checks['ingest_writer'] = $buffered === null ? false : $buffered < 50_000;

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'buffered_events' => $buffered,
        ], $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private function check(\Closure $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
