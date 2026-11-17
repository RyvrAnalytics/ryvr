<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for the dashboard, whatever web server sits in front.
 * The collector, tracker and embed routes are excluded: they run outside the web group
 * and embed pages send their own Content-Security-Policy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before the view renders, so @vite, @fonts and the inline scripts carry the nonce.
        $nonce = config('ryvr.csp.enabled', true) ? Vite::useCspNonce() : null;

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        // A response that brings its own policy (favicon proxy, embed shell) keeps it.
        if ($nonce !== null && ! $response->headers->has('Content-Security-Policy') && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $name = config('ryvr.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers[$name] = self::policy($nonce);
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * Scripts and style elements need the nonce. Style attributes stay allowed: Vue style
     * bindings and server-rendered SVG use them, and they cannot run code. Browsers without
     * style-src-elem fall back to style-src.
     */
    public static function policy(string $nonce): string
    {
        $dev = self::viteDevServer();
        $devSources = $dev ? ' '.implode(' ', $dev) : '';

        $directives = [
            'default-src' => "'self'",
            'script-src' => "'self' 'nonce-{$nonce}'".$devSources,
            'style-src' => "'self' 'unsafe-inline'".$devSources,
            // The Vite dev server injects <style> tags without a nonce.
            'style-src-elem' => $dev ? "'self' 'unsafe-inline'".$devSources : "'self' 'nonce-{$nonce}'",
            'style-src-attr' => "'unsafe-inline'",
            'img-src' => "'self' data: https:".$devSources,
            'font-src' => "'self'".$devSources,
            'connect-src' => "'self'".$devSources,
            'frame-ancestors' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'object-src' => "'none'",
        ];

        return implode('; ', array_map(fn ($name, $value) => "{$name} {$value}", array_keys($directives), $directives));
    }

    /**
     * Origins of a running Vite dev server (npm run dev writes public/hot), plus its HMR
     * websocket. Empty when assets come from the build.
     *
     * @return list<string>
     */
    private static function viteDevServer(): array
    {
        if (! Vite::isRunningHot()) {
            return [];
        }

        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $hostPort = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $ws = $parts['scheme'] === 'https' ? 'wss' : 'ws';

        return ["{$parts['scheme']}://{$hostPort}", "{$ws}://{$hostPort}"];
    }
}
