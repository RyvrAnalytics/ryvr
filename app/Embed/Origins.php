<?php

namespace App\Embed;

/**
 * Origin patterns for embed keys and tokens.
 *
 * Accepted patterns: https://host[:port], https://*.host[:port] (any subdomain, not the bare
 * host), and plain-HTTP loopback for development (http://localhost[:port], http://127.0.0.1[:port]).
 * No paths, no "*" on its own, no other plain-HTTP hosts.
 */
final class Origins
{
    private const PATTERN = '/^(https):\/\/(\*\.)?((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}|localhost)(?::(\d{1,5}))?$/';

    private const LOOPBACK = '/^http:\/\/(localhost|127\.0\.0\.1)(?::(\d{1,5}))?$/';

    /**
     * Normalise a pattern a person typed, or return null when it is not acceptable.
     */
    public static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = rtrim($value, '/');

        if (strlen($value) > 255) {
            return null;
        }

        if (preg_match(self::LOOPBACK, $value, $m)) {
            return self::validPort($m[2] ?? null) ? $value : null;
        }

        if (! preg_match(self::PATTERN, $value, $m)) {
            return null;
        }

        if ($m[2] !== '' && ! str_contains($m[3], '.')) {
            return null; // "*.localhost" or "*.com" style patterns are too broad.
        }

        return self::validPort($m[4] ?? null) ? $value : null;
    }

    /**
     * Whether a concrete origin sent by a browser matches one pattern.
     */
    public static function matches(string $origin, string $pattern): bool
    {
        $origin = strtolower($origin);

        if (! str_contains($pattern, '*')) {
            return hash_equals($pattern, $origin);
        }

        // https://*.example.com[:port] matches https://<one or more labels>.example.com[:port].
        [$scheme, $rest] = explode('://*.', $pattern, 2);
        $prefix = $scheme.'://';
        if (! str_starts_with($origin, $prefix)) {
            return false;
        }

        $host = substr($origin, strlen($prefix));
        $suffix = '.'.$rest;

        return strlen($host) > strlen($suffix)
            && str_ends_with($host, $suffix)
            && (bool) preg_match('/^[a-z0-9.-]+$/', substr($host, 0, -strlen($suffix)));
    }

    /**
     * @param  iterable<string>  $patterns
     */
    public static function matchesAny(string $origin, iterable $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($origin, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every origin pattern in $claimed is covered by a key pattern. A wildcard claim
     * is only covered by the same (or a broader) wildcard.
     *
     * @param  list<string>  $keyPatterns
     */
    public static function covers(array $keyPatterns, string $claimed): bool
    {
        if (in_array($claimed, $keyPatterns, true)) {
            return true;
        }

        if (str_contains($claimed, '*')) {
            // https://*.a.example.com is covered by https://*.example.com.
            [$scheme, $rest] = explode('://*.', $claimed, 2);
            foreach ($keyPatterns as $pattern) {
                if (str_starts_with($pattern, $scheme.'://*.')) {
                    $keyRest = substr($pattern, strlen($scheme.'://*.'));
                    if ($rest === $keyRest || str_ends_with($rest, '.'.$keyRest)) {
                        return true;
                    }
                }
            }

            return false;
        }

        return self::matchesAny($claimed, $keyPatterns);
    }

    private static function validPort(?string $port): bool
    {
        return $port === null || $port === '' || ((int) $port >= 1 && (int) $port <= 65535);
    }
}
