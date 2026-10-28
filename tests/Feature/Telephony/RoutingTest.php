<?php

use App\Authorization\Permission;
use App\Authorization\Role;
use App\Models\CallQueue;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Holiday;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\PbxServer;
use App\Models\RingGroup;
use App\Models\SipTrunk;
use App\Models\User;
use App\Pbx\Routing\RoutingCompiler;
use App\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAsterisk;

function routingOwner(): User
{
    $owner = User::factory()->create();
    $owner->organization->forceFill(['pbx_server_id' => PbxServer::factory()->create()->id, 'timezone' => 'Asia/Colombo'])->save();

    return $owner;
}

function routingExtension(User $owner, string $number, array $attributes = []): Extension
{
    return app(Tenant::class)->runAs($owner->organization, fn () => Extension::factory()->create([
        'organization_id' => $owner->organization_id,
        'number' => $number,
        ...$attributes,
    ]));
}

function compileFor(User $owner): array
{
    return app(RoutingCompiler::class)->compile($owner->organization->fresh());
}

it('translates outbound patterns into anchored regular expressions', function (string $pattern, string $number, bool $matches) {
    expect((new OutboundRoute(['pattern' => $pattern]))->matches($number))->toBe($matches);
})->with([
    ['07XXXXXXXX', '0771234567', true],
    ['07XXXXXXXX', '077123456', false],
    ['00X.', '0044207946', true],
    ['+94X.', '+94771234567', true],
    ['+94X.', '94771234567', false],
    ['0[1-3]XXXXXXXX', '0112345678', true],
    ['0[1-3]XXXXXXXX', '0412345678', false],
    ['NXX', '100', false],
]);

it('pushes a ring group to the PBX and clears it again when deleted', function () {
    $pbx = FakeAsterisk::install();
    $owner = routingOwner();
    $a = routingExtension($owner, '100');
    $b = routingExtension($owner, '101');
    $o = $owner->organization_id;

    $this->actingAs($owner)->post(route('ring-groups.store'), [
        'number' => '600', 'name' => 'Sales', 'strategy' => 'sequential', 'ring_timeout' => 15,
        'members' => [$b->id, $a->id], 'fallback_destination' => "voicemail:{$a->id}",
    ])->assertSessionHas('success');

    expect($pbx->globals["CPBX_O{$o}_N_600"])->toBe('ringgroup')
        ->and($pbx->globals["CPBX_O{$o}_RG_600"])->toBe("sequential|15|o{$o}_101&o{$o}_100|vm:o{$o}_100")
        ->and($owner->organization->fresh()->pbx_routing_error)->toBeNull();

    $group = RingGroup::withoutGlobalScopes()->sole();
    $this->actingAs($owner)->delete(route('ring-groups.destroy', $group->id));

    expect($pbx->globals["CPBX_O{$o}_RG_600"])->toBe('')
        ->and($pbx->globals["CPBX_O{$o}_N_600"])->toBe('');
});

it('keeps one internal number space across extensions, groups, queues, rooms and menus', function () {
    FakeAsterisk::install();
    $owner = routingOwner();
    $ext = routingExtension($owner, '100');

    $this->actingAs($owner)->post(route('ring-groups.store'), [
        'number' => '100', 'name' => 'Clash', 'strategy' => 'ringall', 'ring_timeout' => 20, 'members' => [$ext->id],
    ])->assertSessionHasErrors(['number' => '100 is already used by a extension.']);

    $this->actingAs($owner)->post(route('conferences.store'), ['number' => '800', 'name' => 'Room', 'max_members' => 5]);
    $this->actingAs($owner)->post(route('extensions.store'), ['number' => '800', 'name' => 'Clash'])->assertSessionHasErrors('number');
    $this->actingAs($owner)->post(route('queues.store'), [
        'number' => '800', 'name' => 'Q', 'strategy' => 'rrmemory', 'agent_timeout' => 15, 'wrapup_time' => 5, 'max_wait' => 300,
    ])->assertSessionHasErrors('number');
});

it('rejects destinations that belong to another organization', function () {
    FakeAsterisk::install();
    $owner = routingOwner();
    $foreign = routingExtension(routingOwner(), '100');

    $this->actingAs($owner)->post(route('dids.store'), ['number' => '94112345678', 'destination' => "extension:{$foreign->id}"])
        ->assertSessionHasErrors('destination');
    $this->actingAs($owner)->post(route('dids.store'), ['number' => '94112345678', 'destination' => 'external:0771234567'])
        ->assertSessionHas('success');

    // A DID routes to exactly one tenant.
    $this->actingAs(routingOwner())->post(route('dids.store'), ['number' => '+94 11 234 5678', 'destination' => 'hangup:'])
        ->assertSessionHasErrors('number');
});

it('compiles DIDs, open hours, holidays, queues, IVRs and outbound routes', function () {
    FakeAsterisk::install();
    $owner = routingOwner();
    $o = $owner->organization_id;
    $ext = routingExtension($owner, '100');
    routingExtension($owner, '101', ['status' => Extension::STATUS_DISABLED]);

    app(Tenant::class)->runAs($owner->organization, function () use ($ext) {
        $trunk = SipTrunk::create(['name' => 'Carrier', 'host' => 'sip.carrier.test', 'port' => 5060, 'transport' => 'udp', 'max_channels' => 0, 'codecs' => 'ulaw,alaw', 'status' => 'active', 'caller_id' => '94110000000']);
        OutboundRoute::create(['sip_trunk_id' => $trunk->id, 'name' => 'Mobiles', 'pattern' => '07XXXXXXXX', 'strip_digits' => 1, 'prepend' => '+94', 'priority' => 10]);
        $queue = CallQueue::create(['number' => '700', 'name' => 'Support', 'strategy' => 'rrmemory', 'agent_timeout' => 15, 'wrapup_time' => 5, 'max_wait' => 120, 'fallback_destination' => "extension:{$ext->id}"]);
        IvrMenu::create(['number' => '900', 'name' => 'Main', 'options' => [['digit' => '1', 'destination' => "queue:{$queue->id}"], ['digit' => '*', 'destination' => 'hangup:']], 'timeout' => 5, 'max_retries' => 2, 'published_at' => now()]);
        IvrMenu::create(['number' => '901', 'name' => 'Draft', 'options' => [], 'timeout' => 5, 'max_retries' => 2]);
        $ivr = IvrMenu::where('number', '900')->sole();
        DidNumber::create(['number' => '94112345678', 'destination' => "ivr:{$ivr->id}", 'after_hours_destination' => "voicemail:{$ext->id}"]);
        DidNumber::create(['number' => '94112345679', 'destination' => 'ivr:999999']);
        Holiday::create(['name' => 'Vesak', 'date' => now()->addMonth()->toDateString(), 'recurring' => false]);
        Holiday::create(['name' => 'Last year', 'date' => now()->subYear()->toDateString(), 'recurring' => false]);
    });

    $globals = compileFor($owner);
    $trunkId = SipTrunk::withoutGlobalScopes()->sole()->endpointId();

    expect($globals['CPBX_DID_94112345678'])->toBe("{$o}|num:900|vm:o{$o}_100")
        ->and($globals['CPBX_DID_94112345679'])->toBe("{$o}|hangup:|hangup:")
        ->and($globals["CPBX_O{$o}_Q_700"])->toBe("o{$o}_q700|120|num:100")
        ->and($globals["CPBX_O{$o}_IVR_900_1"])->toBe('num:700')
        ->and($globals["CPBX_O{$o}_IVR_900_STAR"])->toBe('hangup:')
        ->and($globals)->not->toHaveKey("CPBX_O{$o}_IVR_901")
        ->and($globals["CPBX_O{$o}_HOURS"])->toBe('5')
        ->and($globals["CPBX_O{$o}_HOURS_1"])->toBe('09:00-17:00,mon,*,*,Asia/Colombo')
        ->and($globals["CPBX_O{$o}_CLOSED"])->toBe('1')
        ->and($globals["CPBX_O{$o}_OUT"])->toBe('1')
        ->and($globals["CPBX_O{$o}_OUT_1"])->toBe("^07[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]$|{$trunkId}|1|+94|94110000000")
        ->and($globals["CPBX_O{$o}_REC"])->toBe('none');
});

it('provisions SIP trunks with an IP match and optional registration', function () {
    $pbx = FakeAsterisk::install();
    $owner = routingOwner();

    $this->actingAs($owner)->post(route('trunks.store'), [
        'name' => 'Carrier', 'host' => 'sip.carrier.test', 'port' => 5060, 'transport' => 'udp',
        'username' => 'acct1', 'password' => 'pw-123', 'register' => true,
        'max_channels' => 10, 'codecs' => 'ulaw,alaw', 'status' => 'active',
    ])->assertSessionHas('success');

    $trunk = SipTrunk::withoutGlobalScopes()->sole();
    $id = $trunk->endpointId();

    expect($trunk->provisioning_status)->toBe('provisioned')
        ->and($pbx->endpoint($id)['context'])->toBe('cloudpbx-inbound')
        ->and($pbx->endpoint($id)['set_var'])->toContain("CLOUDPBX_ORG={$owner->organization_id}")
        ->and($pbx->objects['identify'][$id]['match'])->toBe('sip.carrier.test')
        ->and($pbx->objects['registration'][$id]['client_uri'])->toBe('sip:acct1@sip.carrier.test')
        ->and($pbx->objects['auth'][$id]['password'])->toBe('pw-123');

    // Editing without a password keeps the stored one; turning registration off removes it.
    $this->actingAs($owner)->put(route('trunks.update', $trunk->id), [
        'name' => 'Carrier', 'host' => 'sip.carrier.test', 'port' => 5060, 'transport' => 'udp',
        'username' => 'acct1', 'password' => null, 'register' => false,
        'max_channels' => 10, 'codecs' => 'ulaw,alaw', 'status' => 'active',
    ]);

    expect($trunk->fresh()->password)->toBe('pw-123')
        ->and($pbx->objects['registration'] ?? [])->not->toHaveKey($id);

    $this->actingAs($owner)->delete(route('trunks.destroy', $trunk->id));
    expect($pbx->endpoint($id))->toBeNull();
});

it('only lets ivr.publish change a live menu, and serves greetings to the PBX by token', function () {
    FakeAsterisk::install();
    Storage::fake('local');
    $owner = routingOwner();
    // No built-in role has ivr.manage without ivr.publish, so grant it directly.
    $manager = User::factory()->for($owner->organization)->role(Role::Supervisor)->create();
    $manager->givePermissionTo([Permission::IVR_VIEW, Permission::IVR_MANAGE]);

    $this->actingAs($manager)->post(route('ivr.store'), ['number' => '900', 'name' => 'Main'])->assertRedirect();
    $menu = IvrMenu::withoutGlobalScopes()->sole();

    $wav = UploadedFile::fake()->createWithContent('hello.wav', 'RIFF'.pack('V', 4).'WAVEfmt ');
    $this->actingAs($manager)->post(route('ivr.greeting.store', $menu->id), ['greeting' => $wav])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post(route('ivr.greeting.store', $menu->id), ['greeting' => UploadedFile::fake()->create('x.wav', 1)])
        ->assertSessionHasErrors('greeting');

    $this->actingAs($manager)->post(route('ivr.publish', $menu->id))->assertForbidden();
    $this->actingAs($owner)->post(route('ivr.publish', $menu->id))->assertSessionHas('success');

    $this->actingAs($manager)->put(route('ivr.update', $menu->id), ['number' => '900', 'name' => 'Renamed', 'timeout' => 5, 'max_retries' => 2])
        ->assertForbidden();

    $menu->refresh();
    $this->get($menu->greetingUrl())->assertOk()->assertHeader('Content-Type', 'audio/wav');
    $this->get(route('pbx-media.ivr', ['ivr' => $menu->id, 'token' => str_repeat('a', 40)]))->assertNotFound();
});

it('lets agents and other tenants nowhere near routing pages', function () {
    FakeAsterisk::install();
    $owner = routingOwner();
    $agent = User::factory()->for($owner->organization)->role(Role::Agent)->create();
    $group = app(Tenant::class)->runAs($owner->organization, fn () => RingGroup::create(['number' => '600', 'name' => 'G', 'members' => [], 'strategy' => 'ringall', 'ring_timeout' => 20]));

    foreach (['trunks.index', 'dids.index', 'routing.index', 'ivr.index', 'ring-groups.index', 'queues.index', 'conferences.index'] as $page) {
        $this->actingAs($agent)->get(route($page))->assertForbidden();
        $this->actingAs($owner)->get(route($page))->assertOk();
    }

    $this->actingAs(routingOwner())->delete(route('ring-groups.destroy', $group->id))->assertNotFound();
});
