<?php

use App\Http\Controllers\CollectController;
use Illuminate\Support\Facades\Route;

// Loaded without the web middleware group: no session, no cookies, no CSRF.
Route::post('/e', CollectController::class)->name('collect');
Route::options('/e', [CollectController::class, 'preflight']);
