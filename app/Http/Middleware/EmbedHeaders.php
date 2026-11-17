<?php

namespace App\Http\Middleware;

use App\Models\EmbedKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for every embed route (API and iframe page). Embed routes run outside the web group:
 * no session, no cookies, no CSRF. This middleware makes sure nothing sets a cookie anyway, and
 * answers CORS for the JSON API from the key's origin allowlist (never with credentials).
 *
 * The token's own origin list is enforced later, by AuthenticateEmbedToken.
 */
class EmbedHeaders
{
    public function handle(Request $request, Closure $next, string $surface = 'api'): Response
    {
        $kid = (string) $request->route('kid');
        $origin = (string) $request->headers->get('Origin', '');
        $key = null;
        $corsOrigin = null;

        if ($surface === 'api' && $origin !== '' && $origin !== self::selfOrigin($request)) {
            $key = EmbedKey::findActive($kid);
            if ($key && $key->allowsOrigin($origin)) {
                $corsOrigin = $origin;
            }
        }

        if ($surface === 'api' && $request->isMethod('OPTIONS')) {
            $response = response()->noContent($corsOrigin ? 204 : 403);
            if ($corsOrigin) {
                $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
                $response->headers->set('Access-Control-Max-Age', '600');
            }
        } else {
            if ($key) {
                $request->attributes->set('embed.key', $key);
            }
            $response = $next($request);
        }

        $headers = $response->headers;
        $headers->remove('Set-Cookie');
        foreach ($headers->getCookies() as $cookie) {
            $headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Cache-Control', 'private, no-store, max-age=0');
        $headers->set('Vary', 'Origin, Authorization');

        if ($surface === 'api') {
            // JSON is never framed; only the dashboard page carries frame-ancestors.
            $headers->set('X-Frame-Options', 'DENY');
            if ($corsOrigin) {
                $headers->set('Access-Control-Allow-Origin', $corsOrigin);
                $headers->set('Access-Control-Expose-Headers', 'Retry-After');
            }
        }

        return $response;
    }

    public static function selfOrigin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }
}
