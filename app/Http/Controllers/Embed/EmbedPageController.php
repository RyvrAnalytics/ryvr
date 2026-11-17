<?php

namespace App\Http\Controllers\Embed;

use App\Http\Controllers\Controller;
use App\Models\EmbedKey;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The iframe page: /embed/v1/{kid}/dashboard. A minimal standalone shell (no app chrome) that
 * loads the embed library and renders the token's widgets.
 *
 * The token arrives in the URL fragment (#token=...) or by postMessage, never in the path or
 * query, so it is not sent to the server, logged, or leaked in Referer. The key id is in the
 * path so frame-ancestors can be sent before any token is seen.
 */
class EmbedPageController extends Controller
{
    public function dashboard(string $kid): Response
    {
        $key = EmbedKey::findActive($kid);

        if (! $key) {
            return response('Not found.', 404, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            ]);
        }

        $nonce = Str::random(32);

        $csp = implode('; ', [
            'frame-ancestors '.implode(' ', $key->allowed_origins),
            "default-src 'none'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
            "img-src 'self' data:",
            "base-uri 'none'",
            "form-action 'none'",
        ]);

        return response()
            ->view('embed.dashboard', [
                'nonce' => $nonce,
                'kid' => $key->public_id,
                'host' => url('/'),
                'script' => '/embed.js?v='.(is_file(public_path('embed.js')) ? (int) filemtime(public_path('embed.js')) : 0),
            ])
            ->header('Content-Security-Policy', $csp);
    }
}
