<?php

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\FaviconController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('sites.index') : redirect()->route('login'))->name('home');

Route::get('invitations/{token}', [MemberController::class, 'showInvitation'])->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('start', [OnboardingController::class, 'show'])->name('onboarding');
    Route::post('start', [OnboardingController::class, 'store'])->name('onboarding.store');
    Route::post('organizations/{organization}/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');

    Route::middleware('org')->group(function () {
        Route::redirect('dashboard', '/sites')->name('dashboard');
        Route::get('icons/{domain}', FaviconController::class)->where('domain', '[A-Za-z0-9.-]+')->middleware('throttle:240,1')->name('icons');

        Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('sites/new', [SiteController::class, 'create'])->name('sites.create');
        Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
        Route::get('sites/{project}', [SiteController::class, 'show'])->name('sites.show');
        Route::get('sites/{project}/breakdown', [SiteController::class, 'breakdown'])->name('sites.breakdown');
        Route::get('sites/{project}/realtime', [SiteController::class, 'realtime'])->name('sites.realtime');
        Route::get('sites/{project}/now', [SiteController::class, 'now'])->name('sites.now');
        Route::get('sites/{project}/export', [SiteController::class, 'export'])->middleware('throttle:10,1')->name('sites.export');

        Route::get('sites/{project}/settings', [SiteSettingsController::class, 'edit'])->name('sites.settings');
        Route::put('sites/{project}/settings', [SiteSettingsController::class, 'update'])->name('sites.update');
        Route::post('sites/{project}/rotate-key', [SiteSettingsController::class, 'rotateKey'])->name('sites.rotate-key');
        Route::post('sites/{project}/archive', [SiteSettingsController::class, 'archive'])->name('sites.archive');
        Route::delete('sites/{project}', [SiteSettingsController::class, 'destroy'])->name('sites.destroy');
        Route::post('sites/{project}/goals', [SiteSettingsController::class, 'storeGoal'])->name('sites.goals.store');
        Route::delete('sites/{project}/goals/{goal}', [SiteSettingsController::class, 'destroyGoal'])->name('sites.goals.destroy');
        Route::post('sites/{project}/exclusions', [SiteSettingsController::class, 'storeRule'])->name('sites.exclusions.store');
        Route::patch('sites/{project}/exclusions/{rule}', [SiteSettingsController::class, 'updateRule'])->name('sites.exclusions.update');
        Route::delete('sites/{project}/exclusions/{rule}', [SiteSettingsController::class, 'destroyRule'])->name('sites.exclusions.destroy');

        Route::prefix('organization')->name('organization.')->group(function () {
            Route::get('/', [OrganizationController::class, 'edit'])->name('edit');
            Route::put('/', [OrganizationController::class, 'update'])->name('update');
            Route::delete('/', [OrganizationController::class, 'destroy'])->name('destroy');
            Route::post('projects/{project}/restore', [OrganizationController::class, 'restoreProject'])->name('projects.restore');
            Route::post('groups', [OrganizationController::class, 'storeGroup'])->name('groups.store');
            Route::delete('groups/{group}', [OrganizationController::class, 'destroyGroup'])->name('groups.destroy');

            Route::get('exclusions', [OrganizationController::class, 'exclusions'])->name('exclusions');
            Route::post('exclusions', [OrganizationController::class, 'storeRule'])->name('exclusions.store');
            Route::patch('exclusions/{rule}', [OrganizationController::class, 'updateRule'])->name('exclusions.update');
            Route::delete('exclusions/{rule}', [OrganizationController::class, 'destroyRule'])->name('exclusions.destroy');

            Route::get('members', [MemberController::class, 'index'])->name('members');
            Route::post('members/invite', [MemberController::class, 'invite'])->middleware('throttle:20,1')->name('members.invite');
            Route::put('members/{membership}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy');
            Route::delete('invitations/{invitation}', [MemberController::class, 'revokeInvitation'])->name('invitations.destroy');

            Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
            Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
            Route::delete('api-keys/{key}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

            Route::get('audit', [OrganizationController::class, 'audit'])->name('audit');
        });
    });
});

require __DIR__.'/settings.php';
