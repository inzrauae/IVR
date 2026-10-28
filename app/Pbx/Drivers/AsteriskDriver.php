<?php

namespace App\Pbx\Drivers;

use App\Models\Extension;
use App\Models\PbxServer;
use App\Pbx\Contracts\PbxDriver;
use App\Pbx\Data\PbxHealth;
use App\Pbx\Exceptions\PbxException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Asterisk over ARI (REST). Extensions are pushed with ARI dynamic configuration, which
 * requires res_pjsip objects to use a writable sorcery backend (see docs/asterisk/).
 */
class AsteriskDriver implements PbxDriver
{
    /** Dialplan context every tenant endpoint starts in (shipped in docs/asterisk/extensions.conf). */
    public const CONTEXT = 'cloudpbx-internal';

    public function __construct(private readonly PbxServer $server) {}

    public function health(): PbxHealth
    {
        if (! $this->server->ari_url || ! $this->server->ari_username) {
            return PbxHealth::offline('ARI URL and username are not configured.');
        }

        try {
            $response = $this->ari()->get('asterisk/info', ['only' => 'build,system,status']);
        } catch (ConnectionException $e) {
            return PbxHealth::offline('Connection failed: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            return PbxHealth::offline('ARI rejected the credentials (HTTP 401).');
        }

        if (! $response->successful()) {
            return PbxHealth::offline('ARI returned HTTP '.$response->status().'.');
        }

        return new PbxHealth(
            online: true,
            version: $response->json('system.version'),
            details: [
                'entity_id' => $response->json('system.entity_id'),
                'startup_time' => $response->json('status.startup_time'),
                'last_reload_time' => $response->json('status.last_reload_time'),
                'os' => $response->json('build.os'),
            ],
        );
    }

    /** Per-extension call-handling globals, read by the dialplan as ${CPBX_<sip id>_<KEY>}. */
    private const ROUTING_KEYS = ['TIMEOUT', 'DND', 'VM', 'FWD', 'FWDBUSY', 'FWDNA'];

    public function provisionExtension(Extension $extension): void
    {
        $id = $extension->sip_username;

        $this->pushObject('auth', $id, [
            'auth_type' => 'userpass',
            'username' => $id,
            'password' => $extension->sip_password,
        ]);

        $this->pushObject('aor', $id, [
            // Several devices (desk phone, softphone, web phone) may register at once.
            'max_contacts' => '5',
            'remove_existing' => 'yes',
            'qualify_frequency' => '30',
        ]);

        $endpoint = [
            'context' => self::CONTEXT,
            'disallow' => 'all',
            'allow' => $extension->webrtc_enabled ? 'opus,ulaw,alaw' : 'ulaw,alaw,g722',
            'auth' => $id,
            'aors' => $id,
            'callerid' => sprintf('"%s" <%s>', str_replace('"', '', $extension->caller_id_name ?: $extension->name), $extension->number),
            'direct_media' => 'no',
            'rtp_symmetric' => 'yes',
            'force_rport' => 'yes',
            'rewrite_contact' => 'yes',
            'device_state_busy_at' => '1',
        ];

        if ($extension->webrtc_enabled) {
            $endpoint['webrtc'] = 'yes';
        }

        // set_var may repeat; the shared dialplan reads these to keep each tenant in its own number space.
        $variables = ["CLOUDPBX_ORG={$extension->organization_id}"];
        if ($extension->caller_id_number) {
            $variables[] = "CLOUDPBX_OUTBOUND_CID={$extension->caller_id_number}";
        }

        $this->pushObject('endpoint', $id, $endpoint, array_map(fn ($v) => ['attribute' => 'set_var', 'value' => $v], $variables));

        // set_var only applies to calls this endpoint makes; calls *to* it are steered by globals.
        foreach ($this->routingVariables($extension) as $key => $value) {
            $this->setGlobal("CPBX_{$id}_{$key}", $value);
        }

        $this->verifyEndpoint($id);
    }

    public function deprovisionExtension(string $sipUsername): void
    {
        foreach (['endpoint', 'aor', 'auth'] as $type) {
            $response = $this->send(fn () => $this->ari()->delete("asterisk/config/dynamic/res_pjsip/{$type}/{$sipUsername}"));

            if (! $response->successful() && $response->status() !== 404) {
                throw new PbxException("Removing {$type} failed: ".$this->describe($response));
            }
        }

        foreach (self::ROUTING_KEYS as $key) {
            $this->setGlobal("CPBX_{$sipUsername}_{$key}", '');
        }
    }

    public function provisionedExtensionIds(): array
    {
        $response = $this->send(fn () => $this->ari()->get('endpoints/PJSIP'));

        if (! $response->successful()) {
            throw new PbxException('Listing endpoints failed: '.$this->describe($response));
        }

        return collect($response->json() ?? [])
            ->pluck('resource')
            ->filter(fn ($resource) => is_string($resource) && Extension::parseSipUsername($resource) !== null)
            ->values()
            ->all();
    }

    public function hangupChannel(string $channelId): void
    {
        $response = $this->send(fn () => $this->ari()->delete('channels/'.rawurlencode($channelId), ['reason' => 'normal']));

        if (! $response->successful() && $response->status() !== 404) {
            throw new PbxException('Hangup failed: '.$this->describe($response));
        }
    }

    public function activeChannelIds(): array
    {
        $response = $this->send(fn () => $this->ari()->get('channels'));

        if (! $response->successful()) {
            throw new PbxException('Listing channels failed: '.$this->describe($response));
        }

        return array_values(array_map(fn (array $c) => (string) $c['id'], $response->json() ?? []));
    }

    /**
     * @param  array<string, string>  $fields
     * @param  list<array{attribute: string, value: string}>  $repeated  attributes that appear more than once
     */
    private function pushObject(string $type, string $id, array $fields, array $repeated = []): void
    {
        $payload = ['fields' => [
            ...collect($fields)->map(fn ($value, $attribute) => ['attribute' => $attribute, 'value' => $value])->values()->all(),
            ...$repeated,
        ]];

        $response = $this->send(fn () => $this->ari()->put("asterisk/config/dynamic/res_pjsip/{$type}/{$id}", $payload));

        if (! $response->successful()) {
            throw new PbxException("Provisioning {$type} {$id} failed: ".$this->describe($response));
        }
    }

    /**
     * @return array<string, string>
     */
    private function routingVariables(Extension $extension): array
    {
        return [
            'TIMEOUT' => (string) ($extension->ring_timeout ?? $extension->organization->setting('call.ring_timeout') ?? 25),
            'DND' => $extension->do_not_disturb ? '1' : '0',
            'VM' => $extension->voicemail_enabled ? '1' : '0',
            'FWD' => (string) $extension->forward_always,
            'FWDBUSY' => (string) $extension->forward_busy,
            'FWDNA' => (string) $extension->forward_no_answer,
        ];
    }

    private function setGlobal(string $name, string $value): void
    {
        $response = $this->send(fn () => $this->ari()->post('asterisk/variable?'.http_build_query(['variable' => $name, 'value' => $value])));

        if (! $response->successful()) {
            throw new PbxException("Setting {$name} failed: ".$this->describe($response));
        }
    }

    /**
     * Read the endpoint back so a push the PBX silently ignored is reported instead of trusted.
     */
    private function verifyEndpoint(string $id): void
    {
        $response = $this->send(fn () => $this->ari()->get("asterisk/config/dynamic/res_pjsip/endpoint/{$id}"));

        if (! $response->successful()) {
            throw new PbxException("Endpoint {$id} is missing on the PBX after provisioning: ".$this->describe($response));
        }

        $context = collect($response->json() ?? [])->firstWhere('attribute', 'context')['value'] ?? null;

        if ($context !== self::CONTEXT) {
            throw new PbxException("Endpoint {$id} has context [{$context}] on the PBX; expected [".self::CONTEXT.'].');
        }
    }

    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new PbxException('Cannot reach the PBX: '.$e->getMessage(), previous: $e);
        }
    }

    private function describe(Response $response): string
    {
        $message = $response->json('message') ?? trim(substr($response->body(), 0, 200));

        return "HTTP {$response->status()}".($message ? " – {$message}" : '');
    }

    private function ari(): PendingRequest
    {
        if (! $this->server->ari_url) {
            throw new PbxException('ARI URL is not configured for this server.');
        }

        return Http::baseUrl(rtrim($this->server->ari_url, '/'))
            ->withBasicAuth((string) $this->server->ari_username, (string) $this->server->ari_password)
            ->timeout(config('pbx.http_timeout'))
            ->acceptJson()
            ->asJson();
    }
}
