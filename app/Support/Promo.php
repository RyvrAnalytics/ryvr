<?php

namespace App\Support;

/**
 * Optional frame in the right column. Off unless set to an https URL (http only in local).
 */
class Promo
{
    /**
     * @return array{url: string, height: int, origin: string}|null
     */
    public static function frame(): ?array
    {
        $url = config('ryvr.promo.frame_url');
        if (! is_string($url) || ($url = trim($url)) === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ! ($scheme === 'http' && app()->environment('local'))) {
            return null;
        }

        $host = strtolower($parts['host']);
        if ($host === '' || str_contains($host, '*')) {
            return null;
        }

        $height = (int) config('ryvr.promo.height', 168);

        return [
            'url' => $url,
            'height' => max(40, min(600, $height > 0 ? $height : 168)),
            'origin' => $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : ''),
        ];
    }
}
