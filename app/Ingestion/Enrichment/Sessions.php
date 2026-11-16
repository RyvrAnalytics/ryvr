<?php

namespace App\Ingestion\Enrichment;

use Illuminate\Support\Facades\Cache;

/**
 * Assigns session ids: a visitor's hits belong to one session until they are inactive
 * for the configured gap (30 minutes by default).
 */
class Sessions
{
    public function sessionId(string $projectId, int $visitorId): int
    {
        $key = 'ryvr:session:'.$projectId.':'.$visitorId;
        $ttl = (int) config('ryvr.ingest.session_timeout', 30) * 60;
        $store = Cache::store();

        $candidate = random_int(1, PHP_INT_MAX >> 3);

        // add() is atomic: only the first hit of a new session wins the race.
        if ($store->add($key, $candidate, $ttl)) {
            return $candidate;
        }

        $existing = (int) $store->get($key, $candidate);
        $store->put($key, $existing, $ttl);

        return $existing;
    }
}
