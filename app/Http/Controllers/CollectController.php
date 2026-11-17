<?php

namespace App\Http\Controllers;

use App\Ingestion\Collector;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * POST /e: the tracker endpoint. Public, cookieless, CORS-open. Always answers quickly and
 * never tells the caller whether a hit was filtered (no oracle for bot authors).
 */
class CollectController
{
    public function __invoke(Request $request, Collector $collector): Response
    {
        if (! config('ryvr.ingest.collector_enabled')) {
            return $this->reply(404);
        }

        $raw = $request->getContent();

        if ($raw === '' || strlen($raw) > config('ryvr.ingest.max_body_bytes')) {
            return $this->reply(400);
        }

        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->reply(400);
        }

        if (! is_array($payload)) {
            return $this->reply(400);
        }

        $limiterKey = 'collect:'.sha1($request->ip().'|'.($payload['k'] ?? ''));
        if (RateLimiter::tooManyAttempts($limiterKey, config('ryvr.ingest.rate_limit'))) {
            return $this->reply(202);
        }
        RateLimiter::hit($limiterKey, 60);

        $result = $collector->collect($payload, $request);

        return $this->reply($result === 'invalid' ? 400 : 202);
    }

    public function preflight(): Response
    {
        return $this->reply(204);
    }

    private function reply(int $status): Response
    {
        return response('', $status, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
            'Cache-Control' => 'no-store',
        ]);
    }
}
