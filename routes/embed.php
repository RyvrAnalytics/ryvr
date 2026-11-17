<?php

use App\Http\Controllers\Embed\EmbedDataController;
use App\Http\Controllers\Embed\EmbedPageController;
use App\Http\Middleware\AuthenticateEmbedToken;
use App\Http\Middleware\EmbedHeaders;
use Illuminate\Support\Facades\Route;

// Embedded analytics. Loaded without the web middleware group: no session, no cookies, no CSRF.
// Authentication is a short-lived embed token (Authorization: Bearer), never a cookie.
Route::prefix('embed/v1/{kid}')
    ->where(['kid' => 'ek_[a-z0-9]{20}'])
    ->group(function () {
        Route::get('dashboard', [EmbedPageController::class, 'dashboard'])
            ->middleware([EmbedHeaders::class.':page', 'throttle:120,1'])
            ->name('embed.dashboard');

        Route::middleware([EmbedHeaders::class.':api'])->group(function () {
            // CORS preflight carries no token; EmbedHeaders answers it from the key's origins.
            Route::options('{path}', fn () => response()->noContent())->where('path', '.*');

            Route::middleware(AuthenticateEmbedToken::class)->group(function () {
                Route::get('meta', [EmbedDataController::class, 'meta'])->name('embed.meta');
                Route::get('summary', [EmbedDataController::class, 'summary'])->name('embed.summary');
                Route::get('trend', [EmbedDataController::class, 'trend'])->name('embed.trend');
                Route::get('top/pages', [EmbedDataController::class, 'topPages'])->name('embed.top-pages');
                Route::get('top/sources', [EmbedDataController::class, 'topSources'])->name('embed.top-sources');
                Route::get('realtime', [EmbedDataController::class, 'realtime'])->name('embed.realtime');
            });
        });
    });
