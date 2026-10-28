<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\CallHistoryController;
use App\Http\Controllers\ConferenceController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DidController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\IvrController;
use App\Http\Controllers\LiveCallController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrganizationSettings;
use App\Http\Controllers\Platform;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RingGroupController;
use App\Http\Controllers\RoutingController;
use App\Http\Controllers\TrunkController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoicemailController;
use App\Http\Controllers\WebPhoneController;
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

    Route::get('extensions', [ExtensionController::class, 'index'])->name('extensions.index');
    Route::post('extensions', [ExtensionController::class, 'store'])->name('extensions.store');
    Route::get('extensions/{extension}', [ExtensionController::class, 'show'])->whereNumber('extension')->name('extensions.show');
    Route::put('extensions/{extension}', [ExtensionController::class, 'update'])->whereNumber('extension')->name('extensions.update');
    Route::delete('extensions/{extension}', [ExtensionController::class, 'destroy'])->whereNumber('extension')->name('extensions.destroy');
    Route::post('extensions/{extension}/provision', [ExtensionController::class, 'retry'])->whereNumber('extension')->name('extensions.provision');
    Route::post('extensions/{extension}/credentials', [ExtensionController::class, 'credentials'])->whereNumber('extension')->name('extensions.credentials');
    Route::post('extensions/{extension}/password', [ExtensionController::class, 'regeneratePassword'])->whereNumber('extension')->name('extensions.password');

    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('departments.destroy');

    // Calls
    Route::get('live-calls', [LiveCallController::class, 'index'])->name('live-calls.index');
    Route::post('live-calls/{call}/hangup', [LiveCallController::class, 'hangup'])->whereNumber('call')->name('live-calls.hangup');
    Route::post('live-calls/{call}/spy', [LiveCallController::class, 'spy'])->whereNumber('call')->name('live-calls.spy');

    Route::get('web-phone', [WebPhoneController::class, 'index'])->name('web-phone.index');
    Route::get('web-phone/credentials', [WebPhoneController::class, 'credentials'])->name('web-phone.credentials');

    Route::get('call-history', [CallHistoryController::class, 'index'])->name('call-history.index');
    Route::get('call-history/export', [CallHistoryController::class, 'export'])->name('call-history.export');
    Route::get('call-history/{call}', [CallHistoryController::class, 'show'])->whereNumber('call')->name('call-history.show');

    // Routing
    Route::get('trunks', [TrunkController::class, 'index'])->name('trunks.index');
    Route::post('trunks', [TrunkController::class, 'store'])->name('trunks.store');
    Route::put('trunks/{trunk}', [TrunkController::class, 'update'])->whereNumber('trunk')->name('trunks.update');
    Route::delete('trunks/{trunk}', [TrunkController::class, 'destroy'])->whereNumber('trunk')->name('trunks.destroy');
    Route::post('trunks/{trunk}/provision', [TrunkController::class, 'retry'])->whereNumber('trunk')->name('trunks.provision');

    Route::get('dids', [DidController::class, 'index'])->name('dids.index');
    Route::post('dids', [DidController::class, 'store'])->name('dids.store');
    Route::put('dids/{did}', [DidController::class, 'update'])->whereNumber('did')->name('dids.update');
    Route::delete('dids/{did}', [DidController::class, 'destroy'])->whereNumber('did')->name('dids.destroy');

    Route::get('routing', [RoutingController::class, 'index'])->name('routing.index');
    Route::post('routing', [RoutingController::class, 'store'])->name('routing.store');
    Route::post('routing/publish', [RoutingController::class, 'publish'])->name('routing.publish');
    Route::put('routing/{route}', [RoutingController::class, 'update'])->whereNumber('route')->name('routing.update');
    Route::delete('routing/{route}', [RoutingController::class, 'destroy'])->whereNumber('route')->name('routing.destroy');

    Route::get('ivr', [IvrController::class, 'index'])->name('ivr.index');
    Route::post('ivr', [IvrController::class, 'store'])->name('ivr.store');
    Route::get('ivr/{ivr}', [IvrController::class, 'edit'])->whereNumber('ivr')->name('ivr.edit');
    Route::put('ivr/{ivr}', [IvrController::class, 'update'])->whereNumber('ivr')->name('ivr.update');
    Route::delete('ivr/{ivr}', [IvrController::class, 'destroy'])->whereNumber('ivr')->name('ivr.destroy');
    Route::post('ivr/{ivr}/publish', [IvrController::class, 'publish'])->whereNumber('ivr')->name('ivr.publish');
    Route::post('ivr/{ivr}/greeting', [IvrController::class, 'uploadGreeting'])->whereNumber('ivr')->name('ivr.greeting.store');
    Route::get('ivr/{ivr}/greeting', [IvrController::class, 'greeting'])->whereNumber('ivr')->name('ivr.greeting');

    Route::get('ring-groups', [RingGroupController::class, 'index'])->name('ring-groups.index');
    Route::post('ring-groups', [RingGroupController::class, 'store'])->name('ring-groups.store');
    Route::put('ring-groups/{ringGroup}', [RingGroupController::class, 'update'])->whereNumber('ringGroup')->name('ring-groups.update');
    Route::delete('ring-groups/{ringGroup}', [RingGroupController::class, 'destroy'])->whereNumber('ringGroup')->name('ring-groups.destroy');

    Route::get('queues', [QueueController::class, 'index'])->name('queues.index');
    Route::post('queues', [QueueController::class, 'store'])->name('queues.store');
    Route::put('queues/{queue}', [QueueController::class, 'update'])->whereNumber('queue')->name('queues.update');
    Route::delete('queues/{queue}', [QueueController::class, 'destroy'])->whereNumber('queue')->name('queues.destroy');
    Route::post('queues/{queue}/members/{extension}/pause', [QueueController::class, 'pause'])->whereNumber(['queue', 'extension'])->name('queues.pause');

    Route::get('conferences', [ConferenceController::class, 'index'])->name('conferences.index');
    Route::post('conferences', [ConferenceController::class, 'store'])->name('conferences.store');
    Route::put('conferences/{conference}', [ConferenceController::class, 'update'])->whereNumber('conference')->name('conferences.update');
    Route::delete('conferences/{conference}', [ConferenceController::class, 'destroy'])->whereNumber('conference')->name('conferences.destroy');

    // Activity
    Route::get('recordings', [RecordingController::class, 'index'])->name('recordings.index');
    Route::get('recordings/{recording}/audio', [RecordingController::class, 'play'])->whereNumber('recording')->name('recordings.play');
    Route::get('recordings/{recording}/download', [RecordingController::class, 'download'])->whereNumber('recording')->name('recordings.download');
    Route::delete('recordings/{recording}', [RecordingController::class, 'destroy'])->whereNumber('recording')->name('recordings.destroy');

    Route::get('voicemail', [VoicemailController::class, 'index'])->name('voicemail.index');
    Route::get('voicemail/{message}/audio', [VoicemailController::class, 'play'])->whereNumber('message')->name('voicemail.play');
    Route::patch('voicemail/{message}', [VoicemailController::class, 'update'])->whereNumber('message')->name('voicemail.update');
    Route::delete('voicemail/{message}', [VoicemailController::class, 'destroy'])->whereNumber('message')->name('voicemail.destroy');

    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::get('contacts/export', [ContactController::class, 'export'])->name('contacts.export');
    Route::post('contacts/import', [ContactController::class, 'import'])->name('contacts.import');
    Route::get('contacts/{contact}/calls', [ContactController::class, 'calls'])->whereNumber('contact')->name('contacts.calls');
    Route::put('contacts/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->name('contacts.update');
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->whereNumber('contact')->name('contacts.destroy');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('integrations/webhooks', [IntegrationController::class, 'store'])->name('webhooks.store');
    Route::put('integrations/webhooks/{webhook}', [IntegrationController::class, 'update'])->whereNumber('webhook')->name('webhooks.update');
    Route::delete('integrations/webhooks/{webhook}', [IntegrationController::class, 'destroy'])->whereNumber('webhook')->name('webhooks.destroy');
    Route::post('integrations/webhooks/{webhook}/test', [IntegrationController::class, 'test'])->whereNumber('webhook')->name('webhooks.test');
    Route::post('integrations/webhooks/{webhook}/rotate', [IntegrationController::class, 'rotate'])->whereNumber('webhook')->name('webhooks.rotate');

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

// IVR greetings fetched by Asterisk's media cache; authorized by the token in the path.
Route::get('pbx-media/ivr/{ivr}/{token}.wav', [IvrController::class, 'media'])
    ->whereNumber('ivr')->where('token', '[a-f0-9]{40}')->middleware('throttle:120,1')->name('pbx-media.ivr');

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
