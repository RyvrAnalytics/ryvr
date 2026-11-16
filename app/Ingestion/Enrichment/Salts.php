<?php

namespace App\Ingestion\Enrichment;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One random salt per UTC day. Visitor ids are hashes of (salt, project, IP, user agent),
 * so a visitor cannot be followed across days, and once a salt is deleted nobody,
 * including the operator, can recompute old ids. Salts older than yesterday are purged.
 */
class Salts
{
    public function forDay(CarbonInterface $day): string
    {
        $date = $day->copy()->setTimezone('UTC')->toDateString();

        return Cache::remember('ryvr:salt:'.$date, 3600, function () use ($date) {
            DB::table('daily_salts')->insertOrIgnore([
                'day' => $date,
                'salt' => Str::random(64),
                'created_at' => now(),
            ]);

            return (string) DB::table('daily_salts')->where('day', $date)->value('salt');
        });
    }

    public function visitorId(string $projectId, string $ip, string $userAgent, ?CarbonInterface $at = null): int
    {
        $salt = $this->forDay($at ?? now());

        return self::toUInt60(hash('sha256', $salt.'|'.$projectId.'|'.$ip.'|'.$userAgent));
    }

    public function purgeOld(): int
    {
        return DB::table('daily_salts')->where('day', '<', now()->utc()->subDay()->toDateString())->delete();
    }

    /**
     * First 60 bits of a hex digest: fits a PHP int exactly and a ClickHouse UInt64.
     */
    public static function toUInt60(string $hex): int
    {
        return (int) hexdec(substr($hex, 0, 15));
    }
}
