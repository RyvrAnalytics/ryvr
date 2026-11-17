<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET /icons/{domain}: a site's favicon, fetched by RYVR itself and cached for a week.
 *
 * Fetching server-side keeps visitors' referrer domains away from third-party favicon
 * services. Referrer domains are visitor-controlled, so the fetch only goes to public
 * addresses, pins the connection to the address it vetted, follows no redirects to
 * other hosts and caps the size.
 */
class FaviconController
{
    private const MAX_BYTES = 100_000;

    private const TTL = 604800;

    public function __invoke(string $domain): Response
    {
        $domain = strtolower(trim($domain, '.'));
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $domain)) {
            return $this->missing();
        }

        $disk = Storage::disk('local');
        $key = 'favicons/'.sha1($domain);

        if ($disk->exists($key.'.json')) {
            $meta = json_decode((string) $disk->get($key.'.json'), true) ?: [];
            if (($meta['fetched_at'] ?? 0) > time() - self::TTL) {
                return ($meta['type'] ?? null) && $disk->exists($key.'.bin')
                    ? $this->image((string) $disk->get($key.'.bin'), $meta['type'])
                    : $this->missing();
            }
        }

        [$body, $type] = $this->fetch($domain) ?? $this->fetch('www.'.$domain) ?? [null, null];

        $disk->put($key.'.json', (string) json_encode(['fetched_at' => time(), 'type' => $type]));
        if ($body !== null && $type !== null) {
            $disk->put($key.'.bin', $body);

            return $this->image($body, $type);
        }

        return $this->missing();
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function fetch(string $host): ?array
    {
        $ip = $this->publicAddress($host);
        if ($ip === null) {
            return null;
        }

        try {
            $response = Http::timeout(3)
                ->connectTimeout(2)
                ->withUserAgent('RYVR favicon fetcher')
                ->withOptions([
                    'allow_redirects' => false,
                    // Connect to the address we vetted, not whatever DNS says a moment later.
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]],
                ])
                ->get("https://{$host}/favicon.ico");
        } catch (Throwable) {
            return null;
        }

        $body = $response->body();
        if (! $response->successful() || $body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        $type = $this->sniff($body);

        return $type ? [$body, $type] : null;
    }

    private function publicAddress(string $host): ?string
    {
        $ips = @gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return $ips[0] ?? null;
    }

    /**
     * Trust the bytes, not the server's content type: only real image formats are served.
     */
    private function sniff(string $body): ?string
    {
        return match (true) {
            str_starts_with($body, "\x00\x00\x01\x00") => 'image/x-icon',
            str_starts_with($body, "\x89PNG") => 'image/png',
            str_starts_with($body, 'GIF8') => 'image/gif',
            str_starts_with($body, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    private function image(string $body, string $type): Response
    {
        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age='.self::TTL,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    private function missing(): Response
    {
        return response('', 404, ['Cache-Control' => 'private, max-age=86400']);
    }
}
