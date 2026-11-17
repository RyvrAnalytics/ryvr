<?php

namespace App\Ingestion\Enrichment;

use donatj\UserAgent\UserAgentParser;

/**
 * Browser, OS and device class from the user agent (plus screen width as a tie-breaker).
 */
class UserAgent
{
    private const PLATFORM_NAMES = [
        'Windows' => 'Windows', 'Macintosh' => 'macOS', 'Linux' => 'Linux', 'Android' => 'Android',
        'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'iPod' => 'iOS', 'Chrome OS' => 'ChromeOS', 'CrOS' => 'ChromeOS',
    ];

    /**
     * @return array{browser: string, browser_version: string, os: string, os_version: string, device: string}
     */
    public function parse(string $ua, int $screenWidth = 0): array
    {
        $parsed = (new UserAgentParser)->parse($ua);

        $browser = (string) ($parsed->browser() ?? '');
        $version = (string) ($parsed->browserVersion() ?? '');
        $platform = (string) ($parsed->platform() ?? '');

        if (str_contains($ua, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'OPR/')) {
            $browser = 'Opera';
        } elseif (str_contains($ua, 'SamsungBrowser/')) {
            $browser = 'Samsung Internet';
        } elseif (str_contains($ua, 'Brave')) {
            $browser = 'Brave';
        }

        return [
            'browser' => $browser ?: 'Unknown',
            'browser_version' => explode('.', $version)[0],
            'os' => self::PLATFORM_NAMES[$platform] ?? ($platform ?: 'Unknown'),
            'os_version' => $this->osVersion($ua),
            'device' => $this->device($ua, $platform, $screenWidth),
        ];
    }

    private function device(string $ua, string $platform, int $width): string
    {
        if ($platform === 'iPad' || str_contains($ua, 'Tablet') || ($platform === 'Android' && ! str_contains($ua, 'Mobile'))) {
            return 'tablet';
        }

        if (in_array($platform, ['iPhone', 'iPod'], true) || str_contains($ua, 'Mobile')) {
            return 'mobile';
        }

        // iPadOS reports a desktop Safari user agent; a touch-sized screen gives it away.
        if ($platform === 'Macintosh' && $width > 0 && $width <= 1366 && str_contains($ua, 'Safari') && ! str_contains($ua, 'Chrome')) {
            return $width < 768 ? 'mobile' : 'desktop';
        }

        return 'desktop';
    }

    private function osVersion(string $ua): string
    {
        $patterns = [
            '/Windows NT ([\d.]+)/' => fn ($m) => match ($m[1]) {
                '10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7', default => $m[1],
            },
            '/Mac OS X ([\d_]+)/' => fn ($m) => implode('.', array_slice(explode('_', $m[1]), 0, 2)),
            '/Android ([\d.]+)/' => fn ($m) => explode('.', $m[1])[0],
            '/(?:iPhone|CPU) OS ([\d_]+)/' => fn ($m) => explode('_', $m[1])[0],
        ];

        foreach ($patterns as $regex => $format) {
            if (preg_match($regex, $ua, $m)) {
                return (string) $format($m);
            }
        }

        return '';
    }
}
