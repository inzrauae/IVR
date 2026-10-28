<?php

use App\Authorization\Role;
use App\Jobs\DeprovisionExtension;
use App\Jobs\ProvisionExtension;
use App\Jobs\SyncPbxServer;
use App\Models\AuditLog;
use App\Models\Extension;
use App\Models\PbxServer;
use App\Models\User;
use App\Pbx\PbxManager;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAsterisk;

function ownerOnPbx(?PbxServer $server = null): User
{
    $owner = User::factory()->create();
    $owner->organization->forceFill(['pbx_server_id' => ($server ?? PbxServer::factory()->create())->id])->save();

    return $owner;
}

function extensionFor(User $owner, array $attributes = []): Extension
{
    return app(Tenant::class)->runAs($owner->organization, fn () => Extension::factory()->create([
        'organization_id' => $owner->organization_id,
        ...$attributes,
    ]));
}

it('creates an extension with the next free number and provisions it on Asterisk', function () {
    $pbx = FakeAsterisk::install();
    $owner = ownerOnPbx();

    $this->actingAs($owner)
        ->post(route('extensions.store'), ['name' => 'Reception', 'forward_busy' => '200'])
        ->assertRedirect()
        ->assertSessionHas('success');

    $extension = Extension::withoutGlobalScopes()->where('organization_id', $owner->organization_id)->sole();
    $id = "o{$owner->organization_id}_100";

    expect($extension->number)->toBe('100')
        ->and($extension->sip_username)->toBe($id)
        ->and($extension->provisioning_status)->toBe(Extension::PROVISION_OK)
        ->and($extension->provisioned_at)->not->toBeNull()
        ->and($pbx->endpoint($id)['context'])->toBe('cloudpbx-internal')
        ->and($pbx->endpoint($id)['set_var'])->toContain("CLOUDPBX_ORG={$owner->organization_id}")
        ->and($pbx->objects['auth'][$id]['password'])->toBe($extension->sip_password)
        ->and($pbx->globals["CPBX_{$id}_FWDBUSY"])->toBe('200')
        ->and($pbx->globals["CPBX_{$id}_TIMEOUT"])->toBe('25')
        ->and(AuditLog::where('action', 'extension.created')->where('organization_id', $owner->organization_id)->exists())->toBeTrue();
});

it('records a provisioning failure and succeeds on retry', function () {
    $pbx = FakeAsterisk::install();
    $pbx->failWith = 500;
    $owner = ownerOnPbx();

    $this->actingAs($owner)->post(route('extensions.store'), ['name' => 'Sales']);

    $extension = Extension::withoutGlobalScopes()->where('organization_id', $owner->organization_id)->sole();

    expect($extension->provisioning_status)->toBe(Extension::PROVISION_FAILED)
        ->and($extension->provisioning_error)->toContain('HTTP 500')
        ->and(AuditLog::where('action', 'extension.provisioning_failed')->exists())->toBeTrue();

    $pbx->failWith = null;

    $this->actingAs($owner)
        ->post(route('extensions.provision', $extension->id))
        ->assertSessionHas('success');

    expect($extension->fresh()->provisioning_status)->toBe(Extension::PROVISION_OK)
        ->and($extension->fresh()->provisioning_error)->toBeNull()
        ->and($pbx->endpoint($extension->sip_username))->not->toBeNull();
});

it('fails provisioning clearly when the organization has no PBX server', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->post(route('extensions.store'), ['name' => 'Lobby']);

    $extension = Extension::withoutGlobalScopes()->where('organization_id', $owner->organization_id)->sole();

    expect($extension->provisioning_status)->toBe(Extension::PROVISION_FAILED)
        ->and($extension->provisioning_error)->toContain('No PBX server');
});

it('enforces the package extension limit', function () {
    // Starter allows 3 extensions.
    $owner = ownerOnPbx(PbxServer::factory()->create(['driver' => 'simulator']));
    foreach (['100', '101', '102'] as $number) {
        extensionFor($owner, ['number' => $number]);
    }

    $this->actingAs($owner)
        ->post(route('extensions.store'), ['name' => 'Fourth'])
        ->assertSessionHas('error');

    expect(Extension::withoutGlobalScopes()->where('organization_id', $owner->organization_id)->count())->toBe(3);
});

it('keeps extension numbers unique per organization only', function () {
    $server = PbxServer::factory()->create(['driver' => 'simulator']);
    $owner = ownerOnPbx($server);
    $other = ownerOnPbx($server);
    extensionFor($owner, ['number' => '200']);

    $this->actingAs($owner)
        ->post(route('extensions.store'), ['name' => 'Duplicate', 'number' => '200'])
        ->assertSessionHasErrors('number');

    $this->actingAs($other)
        ->post(route('extensions.store'), ['name' => 'Same number elsewhere', 'number' => '200'])
        ->assertSessionHasNoErrors();

    expect(Extension::withoutGlobalScopes()->where('organization_id', $other->organization_id)->where('number', '200')->exists())->toBeTrue();
});

it('validates assignment and forwarding destinations', function () {
    $owner = ownerOnPbx(PbxServer::factory()->create(['driver' => 'simulator']));
    $stranger = User::factory()->create();
    $extension = extensionFor($owner, ['number' => '100']);

    $this->actingAs($owner)
        ->put(route('extensions.update', $extension->id), [
            'name' => 'Desk',
            'status' => 'active',
            'user_id' => $stranger->id,
            'forward_always' => '100',
            'forward_busy' => 'not-a-number',
        ])
        ->assertSessionHasErrors(['user_id', 'forward_always', 'forward_busy']);
});

it('removes a disabled extension from Asterisk and restores it when enabled', function () {
    $pbx = FakeAsterisk::install();
    $owner = ownerOnPbx();

    $this->actingAs($owner)->post(route('extensions.store'), ['name' => 'Desk']);
    $extension = Extension::withoutGlobalScopes()->where('organization_id', $owner->organization_id)->sole();

    $this->actingAs($owner)->put(route('extensions.update', $extension->id), ['name' => 'Desk', 'status' => 'disabled']);

    expect($pbx->endpoint($extension->sip_username))->toBeNull()
        ->and($pbx->objects['auth'])->not->toHaveKey($extension->sip_username)
        ->and($extension->fresh()->provisioning_status)->toBe(Extension::PROVISION_OK)
        ->and(AuditLog::where('action', 'extension.disabled')->exists())->toBeTrue();

    $this->actingAs($owner)->put(route('extensions.update', $extension->id), ['name' => 'Desk', 'status' => 'active', 'do_not_disturb' => true]);

    expect($pbx->endpoint($extension->sip_username))->not->toBeNull()
        ->and($pbx->globals["CPBX_{$extension->sip_username}_DND"])->toBe('1');
});

it('deprovisions a deleted extension by its SIP username', function () {
    $owner = ownerOnPbx(PbxServer::factory()->create(['driver' => 'simulator']));
    $extension = extensionFor($owner, ['number' => '150']);
    Queue::fake();

    $this->actingAs($owner)
        ->delete(route('extensions.destroy', $extension->id))
        ->assertRedirect(route('extensions.index'));

    expect(Extension::withoutGlobalScopes()->find($extension->id))->toBeNull();
    Queue::assertPushed(DeprovisionExtension::class, fn ($job) => $job->sipUsername === $extension->sip_username
        && $job->pbxServerId === $owner->organization->pbx_server_id);
});

it('reveals SIP credentials only to managers and audits it', function () {
    $owner = ownerOnPbx(PbxServer::factory()->create(['driver' => 'simulator']));
    $manager = User::factory()->for($owner->organization)->role(Role::CallCenterManager)->create();
    $extension = extensionFor($owner);

    $this->actingAs($manager)->get(route('extensions.show', $extension->id))->assertOk();
    $this->actingAs($manager)->post(route('extensions.credentials', $extension->id))->assertForbidden();

    $this->actingAs($owner)
        ->post(route('extensions.credentials', $extension->id))
        ->assertSessionHas('credentials', ['username' => $extension->sip_username, 'password' => $extension->sip_password]);

    expect(AuditLog::where('action', 'extension.credentials_viewed')->where('user_id', $owner->id)->exists())->toBeTrue();
});

it('regenerates the SIP password and pushes it to the PBX', function () {
    $pbx = FakeAsterisk::install();
    $owner = ownerOnPbx();
    $this->actingAs($owner)->post(route('extensions.store'), ['name' => 'Desk']);
    $extension = Extension::withoutGlobalScopes()->where('organization_id', $owner->organization_id)->sole();
    $old = $extension->sip_password;

    $this->actingAs($owner)->post(route('extensions.password', $extension->id))->assertSessionHas('credentials');

    $new = $extension->fresh()->sip_password;
    expect($new)->not->toBe($old)
        ->and($pbx->objects['auth'][$extension->sip_username]['password'])->toBe($new);
});

it('does not expose extensions across organizations', function () {
    $server = PbxServer::factory()->create(['driver' => 'simulator']);
    $owner = ownerOnPbx($server);
    $intruder = ownerOnPbx($server);
    $extension = extensionFor($owner);

    $this->actingAs($intruder)->get(route('extensions.show', $extension->id))->assertNotFound();
    $this->actingAs($intruder)->put(route('extensions.update', $extension->id), ['name' => 'x', 'status' => 'active'])->assertNotFound();
    $this->actingAs($intruder)->delete(route('extensions.destroy', $extension->id))->assertNotFound();
    $this->actingAs($intruder)->post(route('extensions.credentials', $extension->id))->assertNotFound();

    $this->actingAs($intruder)
        ->get(route('extensions.index'))
        ->assertInertia(fn ($page) => $page->component('extensions/Index')->has('extensions', 0));
});

it('forbids agents from viewing or managing extensions', function () {
    $agent = User::factory()->role(Role::Agent)->create();

    $this->actingAs($agent)->get(route('extensions.index'))->assertForbidden();
    $this->actingAs($agent)->post(route('extensions.store'), ['name' => 'x'])->assertForbidden();
});

it('re-provisions every extension and prunes orphans with pbx:sync', function () {
    $pbx = FakeAsterisk::install();
    $server = PbxServer::factory()->create();
    $owner = ownerOnPbx($server);
    $extension = extensionFor($owner, ['number' => '120']);
    $pbx->objects['endpoint']['o999_100'] = ['context' => 'cloudpbx-internal'];

    $this->artisan('pbx:sync', ['server' => $server->id, '--prune' => true])
        ->expectsOutputToContain('synced 1, failed 0, pruned 1')
        ->assertSuccessful();

    expect($pbx->endpoint($extension->sip_username))->not->toBeNull()
        ->and($pbx->endpoint('o999_100'))->toBeNull()
        ->and($extension->fresh()->provisioning_status)->toBe(Extension::PROVISION_OK);
});

it('re-syncs a PBX server after Asterisk restarts', function () {
    $pbx = FakeAsterisk::install();
    $server = PbxServer::factory()->create();
    app(PbxManager::class)->checkHealth($server);

    Queue::fake();
    app(PbxManager::class)->checkHealth($server->fresh());
    Queue::assertNotPushed(SyncPbxServer::class);

    $pbx->startupTime = '2026-10-28T09:00:00.000+0000';
    app(PbxManager::class)->checkHealth($server->fresh());
    Queue::assertPushed(SyncPbxServer::class, fn ($job) => $job->pbxServerId === $server->id);
});

it('shows registration state on the dashboard', function () {
    $owner = ownerOnPbx(PbxServer::factory()->create(['driver' => 'simulator']));
    extensionFor($owner, ['number' => '100'])->forceFill(['registered' => true])->save();
    extensionFor($owner, ['number' => '101']);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('extensions.registered', 1)->where('extensions.total', 2));
});

it('re-pushes extensions that use the organization ring timeout when it changes', function () {
    $pbx = FakeAsterisk::install();
    $owner = ownerOnPbx();
    $default = extensionFor($owner, ['number' => '100']);
    $custom = extensionFor($owner, ['number' => '101', 'ring_timeout' => 40]);

    $this->actingAs($owner)->put(route('organization-settings.calls.update'), [
        ...$owner->organization->setting('call'),
        'ring_timeout' => 15,
    ])->assertSessionHas('success');

    expect($pbx->globals["CPBX_{$default->sip_username}_TIMEOUT"])->toBe('15')
        ->and($pbx->globals)->not->toHaveKey("CPBX_{$custom->sip_username}_TIMEOUT");
});

it('moves extensions when the platform reassigns the organization to another PBX server', function () {
    $old = PbxServer::factory()->create(['driver' => 'simulator']);
    $new = PbxServer::factory()->create(['driver' => 'simulator']);
    $owner = ownerOnPbx($old);
    $extension = extensionFor($owner, ['number' => '100']);
    Queue::fake();

    $this->actingAs(User::factory()->platformAdmin()->create())
        ->put(route('platform.organizations.pbx-server', $owner->organization), ['pbx_server_id' => $new->id])
        ->assertSessionHas('success');

    Queue::assertPushed(DeprovisionExtension::class, fn ($job) => $job->pbxServerId === $old->id && $job->sipUsername === $extension->sip_username);
    Queue::assertPushed(ProvisionExtension::class, fn ($job) => $job->extensionId === $extension->id);
    expect($extension->fresh()->provisioning_status)->toBe(Extension::PROVISION_PENDING);
});
