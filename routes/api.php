<?php

use App\Http\Controllers\Api\EmbedTokenController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

// REST API v1: read-only reports, authenticated with ryvr_pat_ / ryvr_org_ keys.
Route::prefix('v1')->middleware('api.key:analytics:read')->group(function () {
    Route::get('usage', [UsageController::class, 'api']);
    Route::get('sites', [ReportController::class, 'sites']);
    Route::get('sites/compare', [ReportController::class, 'compare']);
    Route::get('realtime', [ReportController::class, 'realtime']);
    Route::get('sites/{site}/overview', [ReportController::class, 'overview']);
    Route::get('sites/{site}/trend', [ReportController::class, 'trend']);
    Route::get('sites/{site}/top/{dimension}', [ReportController::class, 'top']);
    Route::get('sites/{site}/goals', [ReportController::class, 'goals']);
});

// Embed tokens, minted server-to-server for customers without a JWT library.
Route::prefix('v1/embed')->middleware('api.key:embed:mint')->group(function () {
    Route::post('tokens', [EmbedTokenController::class, 'store']);
    Route::post('tokens/{jti}/revoke', [EmbedTokenController::class, 'revoke']);
});
