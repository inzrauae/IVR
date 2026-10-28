<?php

use App\Models\Organization;
use App\Models\PbxServer;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('restricts the platform portal to platform admins', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('platform.dashboard'))->assertForbidden();
    $this->actingAs($owner)->get(route('platform.organizations.index'))->assertForbidden();
});

it('redirects platform admins away from the organization portal', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('platform.dashboard'));
});

it('shows platform-wide statistics', function () {
    $admin = User::factory()->platformAdmin()->create();
    Organization::factory()->count(2)->create();
    Organization::factory()->suspended()->create();

    $this->actingAs($admin)
        ->get(route('platform.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/Dashboard')
            ->where('stats.organizations.total', 3)
            ->where('stats.organizations.suspended', 1)
            // 3 active Starter subscriptions at LKR 7,500.
            ->where('stats.mrr', fn ($mrr) => (float) $mrr === 22500.0)
        );
});

it('suspends and reactivates an organization with an audit trail', function () {
    $admin = User::factory()->platformAdmin()->create();
    $org = Organization::factory()->create();

    $this->actingAs($admin)
        ->post(route('platform.organizations.suspend', $org), ['reason' => 'Chargeback'])
        ->assertSessionHas('success');

    expect($org->fresh()->isSuspended())->toBeTrue();

    $this->actingAs($admin)->post(route('platform.organizations.reactivate', $org));

    expect($org->fresh()->status)->toBe(Organization::STATUS_ACTIVE)
        ->and($org->fresh()->suspension_reason)->toBeNull()
        ->and(\App\Models\AuditLog::where('organization_id', $org->id)->pluck('action')->all())
        ->toContain('organization.suspended', 'organization.reactivated');
});

it('stores PBX credentials encrypted', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)->post(route('platform.pbx-servers.store'), [
        'name' => 'Colombo 1',
        'driver' => 'asterisk',
        'ari_url' => 'http://10.0.0.5:8088/ari',
        'ari_username' => 'laravel',
        'ari_password' => 'super-secret',
    ])->assertSessionHas('success');

    $raw = DB::table('pbx_servers')->where('name', 'Colombo 1')->value('ari_password');

    expect($raw)->not->toBe('super-secret')
        ->and(PbxServer::where('name', 'Colombo 1')->first()->ari_password)->toBe('super-secret');
});

it('records an online status from a successful ARI health check', function () {
    Http::fake([
        'pbx.test:8088/ari/asterisk/info*' => Http::response([
            'system' => ['version' => '22.1.0', 'entity_id' => 'aa:bb'],
            'status' => ['startup_time' => '2026-10-01T00:00:00.000+0000'],
            'build' => ['os' => 'Linux'],
        ]),
    ]);

    $admin = User::factory()->platformAdmin()->create();
    $server = PbxServer::factory()->create();

    $this->actingAs($admin)
        ->post(route('platform.pbx-servers.health', $server))
        ->assertSessionHas('success');

    $server->refresh();
    expect($server->status)->toBe(PbxServer::STATUS_ONLINE)
        ->and($server->last_health_payload['version'])->toBe('22.1.0');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('laravel:secret')));
});

it('records an offline status when ARI rejects credentials', function () {
    Http::fake(['*' => Http::response([], 401)]);

    $admin = User::factory()->platformAdmin()->create();
    $server = PbxServer::factory()->create();

    $this->actingAs($admin)
        ->post(route('platform.pbx-servers.health', $server))
        ->assertSessionHas('error');

    expect($server->fresh()->status)->toBe(PbxServer::STATUS_OFFLINE)
        ->and($server->fresh()->last_health_payload['error'])->toContain('401');
});

it('refuses to run the simulator driver in production', function () {
    $server = PbxServer::factory()->create(['driver' => 'simulator']);
    app()->detectEnvironment(fn () => 'production');

    app(\App\Pbx\PbxManager::class)->driver($server);
})->throws(LogicException::class);
