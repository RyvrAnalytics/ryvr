<?php

namespace App\Ingestion\Exclusion;

enum Field: string
{
    case Ip = 'ip';
    case UserAgent = 'user_agent';
    case Path = 'path';
    case Hostname = 'hostname';
    case Referrer = 'referrer';
    case Country = 'country';

    public function label(): string
    {
        return match ($this) {
            self::Ip => 'IP address',
            self::UserAgent => 'User agent',
            self::Path => 'Page path',
            self::Hostname => 'Hostname',
            self::Referrer => 'Referrer',
            self::Country => 'Country code',
        };
    }

    /**
     * @return list<Operator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Ip => [Operator::Equals, Operator::Cidr],
            self::Country => [Operator::Equals],
            default => [Operator::Equals, Operator::Contains, Operator::StartsWith, Operator::Regex],
        };
    }
}
