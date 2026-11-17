<?php

namespace App\Http\Middleware;

use App\Embed\EmbedException;
use App\Embed\EmbedGrant;
use App\Embed\EmbedTokenVerifier;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer authentication for the embed API with short-lived embed tokens. Verifies the token
 * against the key named in the URL, checks the browser Origin against the token's origins,
 * applies per-token, per-key and per-organization rate limits, and binds the EmbedGrant.
 */
class AuthenticateEmbedToken
{
    public function __construct(private readonly EmbedTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ipLimiter = 'embed-rejected:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipLimiter, (int) config('ryvr.embed.rate_limit_rejected_per_ip', 30))) {
            return self::error('rate_limited', 'Too many rejected requests. Retry in a minute.', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($ipLimiter));
        }

        try {
            $grant = $this->verifier->verify((string) $request->bearerToken(), (string) $request->route('kid'));
            $this->checkOrigin($request, $grant);
        } catch (EmbedException $e) {
            RateLimiter::hit($ipLimiter, 60);
            Log::info('embed.rejected', ['reason' => $e->reason, 'kid' => (string) $request->route('kid'), 'origin' => $request->headers->get('Origin')]);

            return self::error($e->reason, $e->getMessage(), $e->status);
        }

        $key = $grant->key;
        foreach ([
            ['embed-token:'.hash('sha256', $key->id.$grant->jti), (int) config('ryvr.embed.rate_limit_per_token', 120)],
            ['embed-key:'.$key->id, $key->rate_limit_per_minute],
            ['embed-org:'.$key->organization_id, (int) config('ryvr.embed.rate_limit_per_org', 3000)],
        ] as [$limiter, $max]) {
            if (RateLimiter::tooManyAttempts($limiter, max(1, $max))) {
                return self::error('rate_limited', 'Too many requests. Slow down and retry in a minute.', 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($limiter));
            }
            RateLimiter::hit($limiter, 60);
        }

        $this->recordUsage($grant, $request);

        app()->instance(EmbedGrant::class, $grant);
        $request->attributes->set('embed.grant', $grant);

        return $next($request);
    }

    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer realm="ryvr-embed"'] : []);
    }

    /**
     * Browsers send Origin on cross-origin requests; it must be one the token names. Requests
     * from RYVR's own embed page (the iframe) are same-origin and pass.
     */
    private function checkOrigin(Request $request, EmbedGrant $grant): void
    {
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || $origin === EmbedHeaders::selfOrigin($request)) {
            return;
        }

        if (! $grant->allowsOrigin($origin)) {
            throw EmbedException::forbidden('origin_not_allowed', 'This page is not allowed to show the embed.');
        }
    }

    /**
     * Cheap usage accounting: a daily counter per key, last-used at most once a minute, and one
     * audit entry per token (not per request).
     */
    private function recordUsage(EmbedGrant $grant, Request $request): void
    {
        $key = $grant->key;
        $counter = 'embed:usage:'.$key->id.':'.now()->format('Ymd');
        Cache::add($counter, 0, 172800);
        Cache::increment($counter);

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $ttl = max(60, $grant->expiresAt - time() + (int) config('ryvr.embed.clock_skew', 60));
        if (Cache::add('embed:seen:'.hash('sha256', $key->id.$grant->jti), true, $ttl)) {
            // Written directly so no viewer IP is stored (privacy: viewers are not RYVR users).
            AuditLog::create([
                'organization_id' => $key->organization_id,
                'actor_type' => 'embed_key',
                'actor_id' => $key->id,
                'action' => 'embed.viewed',
                'subject_type' => 'EmbedKey',
                'subject_id' => $key->id,
                'metadata' => array_filter([
                    'kid' => $key->public_id,
                    'jti' => $grant->jti,
                    'sub' => $grant->sub,
                    'origin' => $request->headers->get('Origin'),
                    'sites' => $grant->projectIds(),
                    'widgets' => $grant->widgets,
                ]),
                'ip_address' => null,
            ]);
        }
    }
}
