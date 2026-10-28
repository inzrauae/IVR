<?php

use App\Authorization\Role;
use App\Models\Call;
use App\Models\Contact;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\PbxServer;
use App\Models\Recording;
use App\Models\User;
use App\Models\VoicemailMessage;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Notifications\VoicemailReceived;
use App\Pbx\Simulator\CallSimulator;
use App\Support\Webhooks;
use App\Tenancy\Tenant;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * @return array{0: User, 1: PbxServer}
 */
function simulatedOrg(): array
{
    $server = PbxServer::factory()->create(['driver' => 'simulator', 'wss_url' => null]);
    $owner = User::factory()->create();
    $owner->organization->forceFill(['pbx_server_id' => $server->id])->save();

    return [$owner, $server];
}

function simExtension(User $owner, string $number, array $attributes = []): Extension
{
    return app(Tenant::class)->runAs($owner->organization, fn () => Extension::factory()->create([
        'organization_id' => $owner->organization_id,
        'number' => $number,
        ...$attributes,
    ]));
}

it('tracks an inbound DID call through live calls and ends it from the portal', function () {
    [$owner, $server] = simulatedOrg();
    $agent = simExtension($owner, '100');
    $did = app(Tenant::class)->runAs($owner->organization, fn () => DidNumber::create(['number' => '94112345678', 'destination' => "extension:{$agent->id}"]));
    $sim = app(CallSimulator::class);

    $linked = $sim->inbound($server, $did, '0771234567', $agent);
    $sim->answer($server, $linked);

    $call = Call::withoutGlobalScopes()->sole();
    expect($call->direction)->toBe(Call::DIRECTION_INBOUND)
        ->and($call->did_number_id)->toBe($did->id)
        ->and($call->to_extension_id)->toBe($agent->id)
        ->and($call->status)->toBe(Call::STATUS_CONNECTED);

    $this->actingAs($owner)->get(route('live-calls.index'))
        ->assertInertia(fn ($page) => $page->component('live-calls/Index')->where('stats.live', 1)->where('calls.0.did', '94112345678'));

    $this->actingAs($owner)->post(route('live-calls.hangup', $call->id))->assertSessionHas('success');

    expect($call->fresh()->status)->toBe(Call::STATUS_COMPLETED);
});

it('shows agents only their own calls and lets managers export history', function () {
    [$owner, $server] = simulatedOrg();
    $mine = simExtension($owner, '100');
    $other = simExtension($owner, '101');
    $agent = User::factory()->for($owner->organization)->role(Role::Agent)->create();
    $mine->forceFill(['user_id' => $agent->id])->save();
    $sim = app(CallSimulator::class);

    $sim->hangup($server, $sim->dial($server, $mine, '101'), '19');
    $sim->hangup($server, $sim->dial($server, $other, '0771234567'), '16');

    $this->actingAs($agent)->get(route('call-history.index'))
        ->assertInertia(fn ($page) => $page->has('calls.data', 1)->where('calls.data.0.from_number', '100'));
    $this->actingAs($owner)->get(route('call-history.index', ['direction' => 'outbound']))
        ->assertInertia(fn ($page) => $page->has('calls.data', 1));

    $foreignCall = Call::withoutGlobalScopes()->where('from_number', '101')->sole();
    $this->actingAs($agent)->get(route('call-history.show', $foreignCall->id))->assertNotFound();
    $this->actingAs($agent)->get(route('call-history.export'))->assertForbidden();

    $csv = $this->actingAs($owner)->get(route('call-history.export'))->assertOk()->streamedContent();
    expect(substr_count(trim($csv), "\n"))->toBe(2);
});

it('stores recordings and voicemail announced by the dialplan and streams them', function () {
    Notification::fake();
    [$owner, $server] = simulatedOrg();
    $owner->organization->updateSettingsSection('notifications', [...$owner->organization->setting('notifications'), 'voicemail_emails' => ['vm@example.com']]);
    $a = simExtension($owner, '100');
    $b = simExtension($owner, '101');
    $agent = User::factory()->for($owner->organization)->role(Role::Agent)->create();
    $a->forceFill(['user_id' => $agent->id])->save();
    $sim = app(CallSimulator::class);

    $linked = $sim->dial($server, $a, '101');
    $sim->answer($server, $linked);
    $sim->hangup($server, $linked);
    $sim->recorded($server, $linked);
    $sim->recorded($server, $linked); // duplicate announcements are ignored

    $recording = Recording::withoutGlobalScopes()->sole();
    expect($recording->call_id)->toBe(Call::withoutGlobalScopes()->sole()->id);

    $this->actingAs($owner)->get(route('recordings.play', $recording->id))->assertOk()->assertHeader('Content-Type', 'audio/wav');
    $this->actingAs($agent)->get(route('recordings.play', $recording->id))->assertForbidden();

    $sim->voicemail($server, $a, '0771234567');
    $sim->voicemail($server, $b, '0779999999');

    expect(VoicemailMessage::withoutGlobalScopes()->count())->toBe(2);
    Notification::assertSentOnDemand(VoicemailReceived::class, fn ($n, $channels, $notifiable) => in_array($agent->email, $notifiable->routes['mail'], true));

    // Agents hear their own mailbox only; playing marks a message heard.
    $this->actingAs($agent)->get(route('voicemail.index'))->assertInertia(fn ($page) => $page->has('messages.data', 1)->where('unheard', 1));
    $theirs = VoicemailMessage::withoutGlobalScopes()->where('extension_id', $b->id)->sole();
    $this->actingAs($agent)->get(route('voicemail.play', $theirs->id))->assertNotFound();
    $own = VoicemailMessage::withoutGlobalScopes()->where('extension_id', $a->id)->sole();
    $this->actingAs($agent)->get(route('voicemail.play', $own->id))->assertOk();
    expect($own->fresh()->heard_at)->not->toBeNull();
});

it('delivers signed webhooks for call events and refuses private URLs', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response('ok', 200)]);
    [$owner, $server] = simulatedOrg();
    $a = simExtension($owner, '100');
    simExtension($owner, '101');

    $this->actingAs($owner)->post(route('webhooks.store'), ['url' => 'https://127.0.0.1/hook', 'events' => ['call.ended']])
        ->assertSessionHasErrors('url');
    $this->actingAs($owner)->post(route('webhooks.store'), ['url' => 'http://93.184.216.34/hook', 'events' => ['call.ended']])
        ->assertSessionHasErrors('url');

    // Stored directly: the test host name does not resolve, which the save-time check allows anyway.
    $webhook = app(Tenant::class)->runAs($owner->organization, fn () => Webhook::create(['url' => 'https://hooks.example.test/pbx', 'events' => ['call.ended'], 'secret' => 'whsec_test']));

    $sim = app(CallSimulator::class);
    $sim->hangup($server, $sim->dial($server, $a, '101'), '19');

    $delivery = WebhookDelivery::sole();
    expect($delivery->event)->toBe('call.ended')
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->payload['data']['call']['status'])->toBe('missed');

    Http::assertSent(function (Request $request) {
        [$t, $v1] = sscanf($request->header('X-CloudPBX-Signature')[0], 't=%d,v1=%s');

        return $request->url() === 'https://hooks.example.test/pbx'
            && $v1 === hash_hmac('sha256', "{$t}.{$request->body()}", 'whsec_test');
    });

    expect(Webhooks::isPublicUrl('https://10.1.2.3/x'))->toBeFalse()
        ->and(Webhooks::isPublicUrl('https://localhost/x'))->toBeFalse()
        ->and(Webhooks::isPublicUrl('https://93.184.216.34/x'))->toBeTrue();
});

it('serves the public API with token auth inside the token owner\'s organization', function () {
    [$owner, $server] = simulatedOrg();
    $a = simExtension($owner, '100');
    simExtension($owner, '101');
    $sim = app(CallSimulator::class);
    $sim->hangup($server, $sim->dial($server, $a, '101'), '19');

    [$other] = simulatedOrg();
    app(Tenant::class)->runAs($other->organization, fn () => Contact::create(['name' => 'Not yours', 'phone' => '0770000000']));

    $token = $owner->createToken('crm')->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

    $this->getJson('/api/v1/calls', $headers)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'missed');
    $this->getJson('/api/v1/extensions', $headers)->assertOk()->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/contacts', ['name' => 'Kamal', 'phone' => '077 123 4567'], $headers)->assertCreated()->assertJsonPath('data.phone', '0771234567');
    $this->getJson('/api/v1/contacts', $headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kamal');
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/calls')->assertUnauthorized();
});

it('imports contacts from CSV and names known callers in call lists', function () {
    [$owner, $server] = simulatedOrg();
    $a = simExtension($owner, '100');
    $csv = "Name,Company,Phone,Email\nNimal Perera,Acme,077 123 4567,nimal@example.com\n,No name,0110000000,\nBad Email,,0112222222,not-an-email\n";

    $this->actingAs($owner)->post(route('contacts.import'), ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)])
        ->assertSessionHas('success', 'Imported: 1 new, 0 updated, 2 skipped (missing name or invalid email).');

    $sim = app(CallSimulator::class);
    $sim->hangup($server, $sim->dial($server, $a, '0771234567'), '16');

    $this->actingAs($owner)->get(route('call-history.index'))->assertInertia(fn ($page) => $page->where('calls.data.0.to_name', 'Nimal Perera'));
    $this->actingAs($owner)->get(route('contacts.export'))->assertOk();
});

it('summarizes calls in reports', function () {
    [$owner, $server] = simulatedOrg();
    $a = simExtension($owner, '100');
    simExtension($owner, '101');
    $sim = app(CallSimulator::class);

    $answered = $sim->dial($server, $a, '101');
    $sim->answer($server, $answered);
    $this->travel(90)->seconds();
    $sim->hangup($server, $answered);
    $sim->hangup($server, $sim->dial($server, $a, '101'), '19');

    $this->actingAs($owner)->get(route('reports.index'))->assertInertia(fn ($page) => $page
        ->component('reports/Index')
        ->where('summary.total', 2)
        ->where('summary.internal', 2)
        ->where('summary.missed', 1)
        ->where('summary.avg_talk', 90)
        ->has('daily', 7)
        ->where('extensions.0.number', '100')
        ->where('extensions.0.calls', 2));

    $this->actingAs($owner)->get(route('reports.export'))->assertOk();
});

it('generates traffic with pbx:simulate and refuses non-simulator servers', function () {
    [$owner] = simulatedOrg();
    simExtension($owner, '100');
    simExtension($owner, '101');

    $this->artisan('pbx:simulate', ['organization' => $owner->organization_id, '--calls' => 6, '--live' => 1])->assertSuccessful();

    expect(Call::withoutGlobalScopes()->finished()->count())->toBe(6)
        ->and(Call::withoutGlobalScopes()->live()->count())->toBe(1);

    $real = User::factory()->create();
    $real->organization->forceFill(['pbx_server_id' => PbxServer::factory()->create()->id])->save();
    $this->artisan('pbx:simulate', ['organization' => $real->organization_id])->assertFailed();
});

it('hands web phone credentials only to a WebRTC extension on a server with WSS', function () {
    [$owner, $server] = simulatedOrg();
    $ext = simExtension($owner, '100', ['user_id' => $owner->id]);

    $this->actingAs($owner)->getJson(route('web-phone.credentials'))->assertStatus(409);

    $server->forceFill(['wss_url' => 'wss://sip.example.test:8089/ws', 'sip_domain' => 'sip.example.test'])->save();
    $this->actingAs($owner)->getJson(route('web-phone.credentials'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('username', $ext->sip_username);

    $ext->forceFill(['webrtc_enabled' => false])->save();
    $owner->unsetRelations();
    $this->actingAs($owner)->getJson(route('web-phone.credentials'))->assertForbidden();

    $this->actingAs($owner)->get(route('web-phone.index', ['dial' => '0771234567']))
        ->assertInertia(fn ($page) => $page->component('web-phone/Index')->where('dial', '0771234567')->missing('extension.sip_password'));
});
