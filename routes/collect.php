<?php

use App\Http\Controllers\CollectController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\IngestSnapshotController;
use Illuminate\Support\Facades\Route;

// Loaded without the web middleware group: no session, no cookies, no CSRF.
Route::post('/e', CollectController::class)->name('collect');
Route::options('/e', [CollectController::class, 'preflight']);
Route::get('/health', HealthController::class)->name('health');

// Ingestion config for an external collector; off unless RYVR_INGEST_SNAPSHOT_TOKEN is set.
Route::get('/internal/ingest/snapshot', IngestSnapshotController::class)->name('ingest.snapshot');
