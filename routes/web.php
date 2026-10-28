<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrganizationSettings;
use App\Http\Controllers\Platform;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if (! $user) {
        return redirect()->route('login');
    }

    return redirect()->route($user->isPlatformAdmin() && ! $user->organization_id ? 'platform.dashboard' : 'dashboard');
})->name('home');

// Organization portal
Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    // Organization settings
    Route::prefix('organization-settings')->name('organization-settings.')->group(function () {
        Route::redirect('/', '/organization-settings/general')->name('index');
        Route::get('logo', [OrganizationSettings\SettingsController::class, 'logo'])->name('logo');
        Route::post('logo', [OrganizationSettings\SettingsController::class, 'uploadLogo'])->name('logo.store');
        Route::delete('logo', [OrganizationSettings\SettingsController::class, 'deleteLogo'])->name('logo.destroy');
        Route::put('general', [OrganizationSettings\SettingsController::class, 'updateGeneral'])->name('general.update');
        Route::put('calls', [OrganizationSettings\SettingsController::class, 'updateCalls'])->name('calls.update');
        Route::put('notifications', [OrganizationSettings\SettingsController::class, 'updateNotifications'])->name('notifications.update');
        Route::put('security', [OrganizationSettings\SettingsController::class, 'updateSecurity'])->name('security.update');
        Route::put('business-hours', [OrganizationSettings\BusinessHoursController::class, 'update'])->name('business-hours.update');
        Route::post('holidays', [OrganizationSettings\BusinessHoursController::class, 'storeHoliday'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [OrganizationSettings\BusinessHoursController::class, 'destroyHoliday'])->name('holidays.destroy');
        Route::post('tokens', [OrganizationSettings\AccessController::class, 'storeToken'])->name('tokens.store');
        Route::delete('tokens/{token}', [OrganizationSettings\AccessController::class, 'destroyToken'])->name('tokens.destroy');
        Route::delete('sessions', [OrganizationSettings\AccessController::class, 'destroyOtherSessions'])->name('sessions.destroy');
        Route::get('{section}', [OrganizationSettings\SettingsController::class, 'show'])->name('show');
    });

    Route::post('billing/plan', [BillingController::class, 'changePlan'])->name('billing.plan');
    Route::put('billing/contact', [BillingController::class, 'updateContact'])->name('billing.contact');

    // Modules registered in config/modules.php that are not built yet.
    foreach (config('modules') as $module) {
        if (! $module['available']) {
            Route::get($module['path'], ModuleController::class)
                ->defaults('module', $module['key'])
                ->name($module['route']);
        }
    }
});

// Billing pages stay reachable while an organization is suspended for non-payment.
Route::middleware(['auth', 'verified', 'tenant:billing'])->group(function () {
    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('billing/invoices/{invoice}', [BillingController::class, 'showInvoice'])->name('billing.invoices.show');
});

// Platform super admin portal
Route::middleware(['auth', 'verified', 'platform'])->prefix('platform')->name('platform.')->group(function () {
    Route::get('/', Platform\DashboardController::class)->name('dashboard');

    Route::get('organizations', [Platform\OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('organizations/{organization}/suspend', [Platform\OrganizationController::class, 'suspend'])->name('organizations.suspend');
    Route::post('organizations/{organization}/reactivate', [Platform\OrganizationController::class, 'reactivate'])->name('organizations.reactivate');
    Route::put('organizations/{organization}/pbx-server', [Platform\OrganizationController::class, 'assignPbxServer'])->name('organizations.pbx-server');

    Route::get('pbx-servers', [Platform\PbxServerController::class, 'index'])->name('pbx-servers.index');
    Route::post('pbx-servers', [Platform\PbxServerController::class, 'store'])->name('pbx-servers.store');
    Route::post('pbx-servers/{pbxServer}/health', [Platform\PbxServerController::class, 'checkHealth'])->name('pbx-servers.health');

    Route::get('invoices', [Platform\BillingController::class, 'index'])->name('invoices.index');
    Route::post('invoices/{invoice}/payments', [Platform\BillingController::class, 'recordPayment'])->name('invoices.payments.store');
    Route::post('invoices/{invoice}/void', [Platform\BillingController::class, 'void'])->name('invoices.void');
    Route::post('billing/run', [Platform\BillingController::class, 'run'])->name('billing.run');

    Route::get('packages', [Platform\PackageController::class, 'index'])->name('packages.index');
    Route::post('packages', [Platform\PackageController::class, 'store'])->name('packages.store');
    Route::put('packages/{package}', [Platform\PackageController::class, 'update'])->name('packages.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
