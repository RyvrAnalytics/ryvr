<?php

namespace App\Http\Controllers;

use App\Ingestion\Collector;
use App\Models\SecretIngestKey;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

class BatchCollectController
{
    public function __invoke(Request $request, Collector $collector): JsonResponse
    {
        abort_unless(config('ryvr.ingest.collector_enabled'), 404);
        $key = SecretIngestKey::findActive((string) $request->bearerToken());
        if (! $key) {
            return response()->json(['message' => 'A valid secret ingest key is required.'], 401, ['WWW-Authenticate' => 'Bearer realm="ryvr"']);
        }

        $limiter = 'batch-key:'.$key->id;
        if (RateLimiter::tooManyAttempts($limiter, (int) config('ryvr.ingest.batch_rate_limit', 120))) {
            return response()->json(['message' => 'Too many requests.'], 429)->header('Retry-After', (string) RateLimiter::availableIn($limiter));
        }
        RateLimiter::hit($limiter, 60);

        if (strlen($request->getContent()) > 500 * (int) config('ryvr.ingest.max_body_bytes')) {
            return response()->json(['message' => 'Batch is too large.'], 413);
        }
        try {
            $events = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return response()->json(['message' => 'Send a JSON array of up to 500 events.'], 422);
        }
        if (! is_array($events) || count($events) > 500) {
            return response()->json(['message' => 'Send a JSON array of up to 500 events.'], 422);
        }

        $accepted = 0;
        foreach ($events as $event) {
            if (! $event instanceof \stdClass) {
                continue;
            }
            $data = (array) $event;
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:120'],
                'url' => ['required', 'string', 'url:http,https', 'max:2048'],
                'referrer' => ['sometimes', 'nullable', 'string', 'max:2048'],
                'props' => ['sometimes', 'nullable', function ($attribute, $value, $fail) {
                    if (! $value instanceof \stdClass) {
                        $fail('Props must be an object.');
                    }
                }],
                'revenue' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000000'],
                'currency' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{3}$/i'],
                'event_id' => ['sometimes', 'nullable', 'string', 'max:255'],
                'ip' => ['sometimes', 'nullable', 'ip'],
                'user_agent' => ['sometimes', 'nullable', 'string', 'max:512'],
            ]);
            if ($validator->fails()) {
                continue;
            }
            try {
                $timestamp = $data['ts'] ?? null;
                if ($timestamp !== null && ((! is_string($timestamp) && ! is_numeric($timestamp)) || $timestamp === '')) {
                    continue;
                }
                $time = $timestamp === null ? CarbonImmutable::now('UTC')
                    : (is_numeric($timestamp) ? CarbonImmutable::createFromTimestampUTC($timestamp) : CarbonImmutable::parse($timestamp, 'UTC')->utc());
                $now = CarbonImmutable::now('UTC');
                $time = $time->max($now->subHours(72))->min($now->addMinutes(5));
            } catch (Throwable) {
                continue;
            }

            $eventRequest = Request::create('/v1/batch', 'POST', server: [
                'REMOTE_ADDR' => $data['ip'] ?? $request->ip(),
                'HTTP_USER_AGENT' => $data['user_agent'] ?? $request->userAgent(),
                'HTTP_ACCEPT_LANGUAGE' => $request->header('Accept-Language', ''),
            ]);
            $payload = ['n' => $data['name'], 'u' => $data['url'], 'r' => $data['referrer'] ?? '',
                'p' => (array) ($data['props'] ?? []), '$' => $data['revenue'] ?? null, 'cur' => $data['currency'] ?? ''];
            $eventId = $data['event_id'] ?? null;
            $collect = fn () => $collector->collect($payload, $eventRequest, $key->project, $time,
                $eventId === null ? null : (Str::isUuid($eventId) ? $eventId : Uuid::uuid5(Uuid::NAMESPACE_URL, $key->project_id.':'.$eventId)->toString()));
            if ($eventId !== null) {
                // Site scope survives key rotation; only successfully accepted events consume an id.
                $cacheKey = 'ryvr:event-id:'.$key->project_id.':'.hash('sha256', $eventId);
                $result = Cache::lock($cacheKey.':lock', 10)->get(function () use ($cacheKey, $collect) {
                    if (Cache::has($cacheKey)) {
                        return 'duplicate';
                    }
                    $result = $collect();
                    if ($result === Collector::ACCEPTED) {
                        Cache::put($cacheKey, true, 86400);
                    }

                    return $result;
                });
            } else {
                $result = $collect();
            }
            if ($result === Collector::ACCEPTED) {
                $accepted++;
            }
        }

        return response()->json(['accepted' => $accepted, 'rejected' => count($events) - $accepted], 202)->header('Cache-Control', 'no-store');
    }
}
