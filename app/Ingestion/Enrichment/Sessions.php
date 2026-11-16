<?php

namespace App\Ingestion\Enrichment;

use Illuminate\Support\Facades\Cache;

/**
 * Assigns session ids: a visitor's hits belong to one session until they are inactive
 * for the configured gap (30 minutes by default).
 *
 * The session also remembers where it came from, so every event in a visit carries the
 * entry referrer, channel and campaign. Filtering "channel = Search" then matches whole
 * visits, not only their first pageview.
 */
class Sessions
{
    public const SOURCE_FIELDS = ['referrer', 'referrer_domain', 'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * @param  array<string, string>  $source  this hit's own source fields
     * @return array{0: int, 1: array<string, string>} session id and the session's source
     */
    public function resolve(string $projectId, int $visitorId, array $source): array
    {
        $key = 'ryvr:session:'.$projectId.':'.$visitorId;
        $ttl = (int) config('ryvr.ingest.session_timeout', 30) * 60;
        $store = Cache::store();

        $fresh = ['id' => random_int(1, PHP_INT_MAX >> 3), 'source' => array_intersect_key($source, array_flip(self::SOURCE_FIELDS))];

        // add() is atomic: only the first hit of a new session wins the race.
        if ($store->add($key, $fresh, $ttl)) {
            return [$fresh['id'], $fresh['source']];
        }

        $session = $store->get($key);
        if (! is_array($session) || ! isset($session['id'])) {
            $session = $fresh;
        }
        $store->put($key, $session, $ttl);

        return [(int) $session['id'], $session['source'] + $fresh['source']];
    }
}
