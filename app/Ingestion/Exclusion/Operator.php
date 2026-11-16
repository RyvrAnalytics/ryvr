<?php

namespace App\Ingestion\Exclusion;

use Symfony\Component\HttpFoundation\IpUtils;

enum Operator: string
{
    case Equals = 'equals';
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case Regex = 'regex';
    case Cidr = 'cidr';

    public function label(): string
    {
        return match ($this) {
            self::Equals => 'is',
            self::Contains => 'contains',
            self::StartsWith => 'starts with',
            self::Regex => 'matches regex',
            self::Cidr => 'is in range (CIDR)',
        };
    }

    public function matches(string $subject, string $value): bool
    {
        return match ($this) {
            self::Equals => strcasecmp($subject, $value) === 0,
            self::Contains => $value !== '' && stripos($subject, $value) !== false,
            self::StartsWith => $value !== '' && stripos($subject, $value) === 0,
            self::Regex => self::regexMatches($subject, $value),
            self::Cidr => $subject !== '' && IpUtils::checkIp($subject, $value),
        };
    }

    public static function validRegex(string $pattern): bool
    {
        return @preg_match(self::delimit($pattern), '') !== false;
    }

    private static function regexMatches(string $subject, string $pattern): bool
    {
        // Rules come from admins, but a bad pattern must never break ingestion.
        return @preg_match(self::delimit($pattern), $subject) === 1;
    }

    private static function delimit(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~i';
    }
}
