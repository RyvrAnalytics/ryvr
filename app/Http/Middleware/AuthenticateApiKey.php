<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Tenancy\Access;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer authentication for the REST API and the MCP server with ryvr_pat_ / ryvr_org_ keys.
 *
 * Usage: ->middleware('api.key:analytics:read') to also require a scope.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $scope = 'analytics:read', string $surface = 'api'): Response
    {
        $token = (string) $request->bearerToken();
        $key = $token !== '' ? ApiKey::findActive($token) : null;

        if (! $key) {
            return $this->deny($request, 401, 'A valid API key is required. Send it as "Authorization: Bearer <key>".');
        }

        if (! $key->hasScope($scope)) {
            return $this->deny($request, 403, "This key is missing the {$scope} permission.");
        }

        if ($surface === 'mcp' && ! $key->organization->mcp_enabled) {
            return $this->deny($request, 403, 'MCP access is turned off for this organization.');
        }

        $access = Access::forApiKey($key);
        if (! $access) {
            return $this->deny($request, 403, 'The owner of this key no longer belongs to the organization.');
        }

        $limiter = 'api-key:'.$key->id;
        if (RateLimiter::tooManyAttempts($limiter, (int) config('ryvr.api.rate_limit', 120))) {
            return $this->deny($request, 429, 'Too many requests. Slow down and retry in a minute.')
                ->header('Retry-After', (string) RateLimiter::availableIn($limiter));
        }
        RateLimiter::hit($limiter, 60);

        // Last-used is shown in the UI; writing it at most once a minute keeps reads cheap.
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
            AuditLog::record($surface === 'mcp' ? 'mcp.used' : 'api.used', $key->organization_id, $key, ['path' => $request->path()], $surface === 'mcp' ? 'mcp_client' : 'api_key', $key->id);
        }

        app()->instance(Access::class, $access);
        $request->attributes->set('access', $access);

        return $next($request);
    }

    private function deny(Request $request, int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $status, $status === 401 ? ['WWW-Authenticate' => 'Bearer realm="ryvr"'] : []);
    }
}
