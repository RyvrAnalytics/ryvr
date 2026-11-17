<?php

use App\Ingestion\Enrichment\Salts;
use Illuminate\Support\Facades\Schedule;

// Create tomorrow's visitor salt a few minutes early, so collectors that sync it never
// meet midnight UTC without one. It is purged on the same two-day schedule as the rest.
Schedule::call(fn () => app(Salts::class)->forDay(now()->utc()->addDay()))->dailyAt('23:50')->name('ryvr:salt:tomorrow')->onOneServer();
Schedule::command('ryvr:prune')->dailyAt('03:20')->withoutOverlapping()->onOneServer();
Schedule::command('ryvr:geoip:update')->monthlyOn(5, '04:10')->withoutOverlapping()->when(fn () => (bool) config('ryvr.geoip.auto_update'));
