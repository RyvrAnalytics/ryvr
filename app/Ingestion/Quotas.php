<?php

namespace App\Ingestion;

use App\Analytics\Analytics;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;

class Quotas
{
    public const REASON = 'quota';

    public function __construct(private readonly Analytics $analytics) {}

    public function allows(Organization $organization): bool
    {
        if ($organization->overage === 'hard_cap' && $organization->monthly_events !== null
            && $this->usedThisMonth($organization->id) >= $organization->monthly_events) {
            return false;
        }

        $rate = $organization->burst_per_second;
        if ($rate <= 0) {
            return true;
        }

        return (bool) Cache::lock('ryvr:burst-lock:'.$organization->id, 5)->get(function () use ($organization, $rate) {
            $key = 'ryvr:burst:'.$organization->id;
            $time = now()->getTimestampMs() / 1000;
            $bucket = Cache::get($key, ['tokens' => $rate, 'time' => $time]);
            $tokens = min($rate, $bucket['tokens'] + max(0, $time - $bucket['time']) * $rate);
            $allowed = $tokens >= 1;
            Cache::put($key, ['tokens' => $allowed ? $tokens - 1 : $tokens, 'time' => $time], 60);

            return $allowed;
        });
    }

    public function usedThisMonth(string $organizationId): int
    {
        return (int) Cache::remember('ryvr:monthly-usage:'.$organizationId.':'.now()->utc()->format('Y-m'), 60,
            fn () => $this->analytics->monthlyOrganizationUsage([$organizationId])[$organizationId] ?? 0);
    }
}
