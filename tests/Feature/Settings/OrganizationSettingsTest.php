<?php

use App\Authorization\Role;
use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('renders every settings section', function (string $section, string $component) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('organization-settings.show', $section))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component("organization-settings/{$component}")->where('canManage', true));
})->with([
    ['general', 'General'],
    ['business-hours', 'BusinessHours'],
    ['calls', 'Calls'],
    ['notifications', 'Notifications'],
    ['security', 'Security'],
    ['diagnostics', 'Diagnostics'],
]);

it('404s unknown settings sections', function () {
    $this->actingAs(User::factory()->create())->get('/organization-settings/nope')->assertNotFound();
});

it('updates general settings', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->put(route('organization-settings.general.update'), [
        'name' => 'Renamed Co', 'timezone' => 'Asia/Colombo', 'locale' => 'si', 'currency' => 'LKR',
        'date_format' => 'd/m/Y', 'contact_email' => 'hi@renamed.test', 'contact_phone' => '+94112223334', 'address' => "1 Main St\nColombo",
    ])->assertSessionHas('success');

    $org = $owner->organization->fresh();
    expect($org->name)->toBe('Renamed Co')
        ->and($org->locale)->toBe('si')
        ->and($org->setting('general.date_format'))->toBe('d/m/Y');
});

it('validates general settings', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('organization-settings.general.update'), ['name' => '', 'timezone' => 'Mars/Base', 'locale' => 'xx', 'currency' => 'ZZZ', 'date_format' => 'nope'])
        ->assertSessionHasErrors(['name', 'timezone', 'locale', 'currency', 'date_format']);
});

it('lets viewers see settings but not change them', function () {
    $owner = User::factory()->create();
    $manager = User::factory()->for($owner->organization)->role(Role::CallCenterManager)->create();
    $manager->givePermissionTo('settings.view');

    $this->actingAs($manager)->get(route('organization-settings.show', 'calls'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false));
    $this->actingAs($manager)->put(route('organization-settings.calls.update'), [])->assertForbidden();
});

it('blocks roles without settings access', function () {
    $this->actingAs(User::factory()->role(Role::Agent)->create())
        ->get(route('organization-settings.show', 'general'))
        ->assertForbidden();
});

it('stores the logo privately and serves it only to members', function () {
    Storage::fake('local');
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('organization-settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->assertSessionHas('success');

    $path = $owner->organization->fresh()->logo_path;
    Storage::disk('local')->assertExists($path);
    $this->actingAs($owner)->get(route('organization-settings.logo'))->assertOk();

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(route('organization-settings.logo'))->assertNotFound();
});

it('saves the weekly business hours', function () {
    $owner = User::factory()->create();
    $hours = collect(range(0, 6))->map(fn ($d) => [
        'day_of_week' => $d, 'is_open' => $d !== 0, 'opens_at' => '08:30', 'closes_at' => $d === 6 ? '13:00' : '17:30',
    ])->all();

    $this->actingAs($owner)->put(route('organization-settings.business-hours.update'), ['hours' => $hours])->assertSessionHas('success');

    $saved = app(Tenant::class)->runAs($owner->organization, fn () => BusinessHour::orderBy('day_of_week')->get());
    expect($saved)->toHaveCount(7)
        ->and($saved[0]->is_open)->toBeFalse()
        ->and(substr($saved[6]->closes_at, 0, 5))->toBe('13:00');
});

it('rejects a closing time before the opening time', function () {
    $hours = collect(range(0, 6))->map(fn ($d) => ['day_of_week' => $d, 'is_open' => true, 'opens_at' => '17:00', 'closes_at' => '09:00'])->all();

    $this->actingAs(User::factory()->create())
        ->put(route('organization-settings.business-hours.update'), ['hours' => $hours])
        ->assertSessionHasErrors('hours.0.closes_at');
});

it('adds holidays and prevents removing another organization\'s holiday', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($other)->post(route('organization-settings.holidays.store'), ['name' => 'Vesak', 'date' => '2027-05-20', 'recurring' => false]);
    $foreign = Holiday::withoutGlobalScopes()->where('organization_id', $other->organization_id)->sole();

    $this->actingAs($owner)->delete(route('organization-settings.holidays.destroy', $foreign->id))->assertNotFound();
    expect($foreign->fresh())->not->toBeNull();

    $this->actingAs($other)->delete(route('organization-settings.holidays.destroy', $foreign->id))->assertSessionHas('success');
    expect($foreign->fresh())->toBeNull();
});

it('saves call settings with dialing restrictions', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->put(route('organization-settings.calls.update'), [
        'default_caller_id' => '+94112345678', 'ring_timeout' => 30, 'max_call_minutes' => 60, 'recording_mode' => 'all',
        'recording_announcement' => true, 'allow_international' => true, 'block_premium' => true, 'hold_music' => 'none',
    ])->assertSessionHas('success');

    $org = $owner->organization->fresh();
    expect($org->setting('call.recording_mode'))->toBe('all')
        ->and($org->setting('call.allow_international'))->toBeTrue()
        ->and($org->setting('call.ring_timeout'))->toBe(30);
});

it('validates notification email lists', function () {
    $this->actingAs(User::factory()->create())->put(route('organization-settings.notifications.update'), [
        'missed_call_emails' => ['not-an-email'], 'voicemail_emails' => [], 'trunk_failure_emails' => [], 'billing_emails' => [],
    ])->assertSessionHasErrors('missed_call_emails.0');
});

it('enforces the IP allowlist and refuses one that locks out the editor', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->put(route('organization-settings.security.update'), ['ip_allowlist' => ['203.0.113.5']])
        ->assertSessionHasErrors('ip_allowlist');

    $this->actingAs($owner)
        ->put(route('organization-settings.security.update'), ['ip_allowlist' => ['127.0.0.1', '10.0.0.0/8']])
        ->assertSessionHas('success');

    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get(route('dashboard'))->assertForbidden();
    $this->actingAs($owner)->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->get(route('dashboard'))->assertOk();
});

it('rejects malformed allowlist entries', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('organization-settings.security.update'), ['ip_allowlist' => ['127.0.0.1', '300.1.1.1', '10.0.0.0/99']])
        ->assertSessionHasErrors(['ip_allowlist.1', 'ip_allowlist.2']);
});

it('creates and revokes personal API tokens that work against the API', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('organization-settings.tokens.store'), ['name' => 'CRM'])->assertSessionHas('token');
    $plain = session('token');

    auth()->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('organization.id', $owner->organization_id);

    $token = $owner->tokens()->sole();
    $this->actingAs($owner)->delete(route('organization-settings.tokens.destroy', $token->id))->assertSessionHas('success');
    expect($owner->tokens()->count())->toBe(0);
});
