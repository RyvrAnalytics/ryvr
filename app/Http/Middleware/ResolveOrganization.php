<?php

namespace App\Http\Middleware;

use App\Tenancy\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organization the signed-in user is working in and binds their Access,
 * so controllers and Inertia props never decide tenancy on their own.
 */
class ResolveOrganization
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();
        $organization = $user?->currentOrganization();

        if (! $user || ! $organization) {
            return $mode === 'optional' ? $next($request) : redirect()->route('onboarding');
        }

        $access = Access::forUser($user, $organization);
        abort_unless($access !== null, 403);

        app()->instance(Access::class, $access);
        $request->attributes->set('access', $access);

        return $next($request);
    }
}
