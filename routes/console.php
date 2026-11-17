<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('ryvr:prune')->dailyAt('03:20')->withoutOverlapping()->onOneServer();
Schedule::command('ryvr:geoip:update')->monthlyOn(5, '04:10')->withoutOverlapping()->when(fn () => (bool) config('ryvr.geoip.auto_update'));
