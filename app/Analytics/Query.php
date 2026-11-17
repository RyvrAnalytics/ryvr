<?php

namespace App\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One analytics question: which projects, which period (in a reporting timezone), which filters.
 *
 * Built only from validated input. The project list is the tenant boundary and is always
 * supplied by the caller's Access, never by request data.
 */
final class Query
{
    /** Filterable dimension => event column. The only column names user input can reach. */
    public const DIMENSIONS = [
        'page' => 'pathname',
        'hostname' => 'hostname',
        'referrer' => 'referrer_domain',
        'channel' => 'channel',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_content' => 'utm_content',
        'utm_term' => 'utm_term',
        'country' => 'country',
        'region' => 'region',
        'city' => 'city',
        'browser' => 'browser',
        'os' => 'os',
        'device' => 'device',
        'language' => 'language',
        'event' => 'name',
    ];

    public const PERIODS = ['today', 'yesterday', '24h', '7d', '30d', '90d', 'month', 'last_month', '12mo', 'year', 'custom'];

    /**
     * @param  list<string>  $projectIds
     * @param  array<string, string>  $filters  dimension => value ("*" wildcards allowed for page)
     */
    public function __construct(
        public readonly array $projectIds,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone = 'UTC',
        public readonly array $filters = [],
        public readonly string $period = 'custom',
    ) {
        foreach (array_keys($filters) as $dimension) {
            if (! isset(self::DIMENSIONS[$dimension])) {
                throw new InvalidArgumentException("Unknown filter [{$dimension}].");
            }
        }
        if ($to <= $from) {
            throw new InvalidArgumentException('The end of the range must be after its start.');
        }
    }

    /**
     * @param  list<string>  $projectIds
     * @param  array<string, string>  $filters
     */
    public static function forPeriod(array $projectIds, string $period, string $timezone = 'UTC', array $filters = [], ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();

        [$start, $end] = match ($period) {
            'today' => [$today, $today->addDay()],
            'yesterday' => [$today->subDay(), $today],
            '24h' => [$now->subDay()->startOfHour(), $now->addHour()->startOfHour()],
            '7d' => [$today->subDays(6), $today->addDay()],
            '90d' => [$today->subDays(89), $today->addDay()],
            'month' => [$today->startOfMonth(), $today->addDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->startOfMonth()],
            '12mo' => [$today->subMonthsNoOverflow(11)->startOfMonth(), $today->addDay()],
            'year' => [$today->startOfYear(), $today->addDay()],
            'custom' => self::customRange($from, $to, $timezone),
            default => [$today->subDays(29), $today->addDay()],
        };

        return new self($projectIds, $start, $end, $timezone, $filters, in_array($period, self::PERIODS, true) ? $period : '30d');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function customRange(?string $from, ?string $to, string $timezone): array
    {
        $start = CarbonImmutable::parse($from ?: 'now', $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to ?: $from ?: 'now', $timezone)->startOfDay()->addDay();
        $max = (int) config('ryvr.query.max_range_days', 3660);

        if ($end <= $start) {
            $end = $start->addDay();
        }
        if ($start->diffInDays($end) > $max) {
            $start = $end->subDays($max);
        }

        return [$start, $end];
    }

    /**
     * The same-length period immediately before this one, for comparisons.
     */
    public function previous(): self
    {
        $seconds = $this->to->getTimestamp() - $this->from->getTimestamp();

        return new self($this->projectIds, $this->from->subSeconds($seconds), $this->from, $this->timezone, $this->filters, 'custom');
    }

    /**
     * @param  array<array-key, string>  $projectIds
     */
    public function withProjects(array $projectIds): self
    {
        return new self(array_values($projectIds), $this->from, $this->to, $this->timezone, $this->filters, $this->period);
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function withFilters(array $filters): self
    {
        return new self($this->projectIds, $this->from, $this->to, $this->timezone, $filters, $this->period);
    }

    /**
     * Bucket size for trend charts.
     */
    public function interval(): string
    {
        $hours = ($this->to->getTimestamp() - $this->from->getTimestamp()) / 3600;

        return match (true) {
            $hours <= 48 => 'hour',
            $hours <= 24 * 120 => 'day',
            default => 'month',
        };
    }

    public function cacheKey(string $kind, mixed ...$extra): string
    {
        $encoded = json_encode([$kind, $this->projectIds, $this->from->getTimestamp(), $this->to->getTimestamp(), $this->timezone, $this->filters, $extra]);

        return 'ryvr:q:'.sha1($encoded === false ? '' : $encoded);
    }
}
