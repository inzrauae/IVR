<?php

namespace App\Pbx\Drivers;

use App\Models\Call;
use App\Models\Extension;
use App\Models\PbxServer;
use App\Models\SipTrunk;
use App\Pbx\Contracts\PbxDriver;
use App\Pbx\Data\PbxHealth;
use App\Pbx\Simulator\CallSimulator;
use LogicException;

/**
 * Local stand-in for development and automated tests when no Asterisk server is available.
 * Provisioning always succeeds; channels are the ones CallSimulator has opened.
 */
class SimulatorDriver implements PbxDriver
{
    public function __construct(private readonly PbxServer $server)
    {
        if (app()->isProduction()) {
            throw new LogicException('The PBX simulator driver cannot be used in production.');
        }
    }

    public function health(): PbxHealth
    {
        return new PbxHealth(
            online: true,
            version: 'simulator',
            details: ['simulated' => true, 'server' => $this->server->name],
        );
    }

    public function provisionExtension(Extension $extension): void {}

    public function deprovisionExtension(string $sipUsername): void {}

    public function provisionedExtensionIds(): array
    {
        return Extension::withoutGlobalScopes()
            ->whereHas('organization', fn ($q) => $q->where('pbx_server_id', $this->server->id))
            ->where('status', Extension::STATUS_ACTIVE)
            ->pluck('sip_username')
            ->all();
    }

    public function setGlobals(array $values): void {}

    public function provisionTrunk(SipTrunk $trunk): void {}

    public function deprovisionTrunk(string $endpointId): void {}

    public function spy(string $supervisorSipId, string $targetChannel, string $mode): void {}

    /**
     * Two seconds of a quiet 440 Hz tone, so playback can be exercised without a PBX.
     */
    public function recordingAudio(string $name): string
    {
        $rate = 8000;
        $samples = '';
        for ($i = 0; $i < $rate * 2; $i++) {
            $samples .= pack('v', (int) (3000 * sin(2 * M_PI * 440 * $i / $rate)));
        }

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }

    public function deleteRecording(string $name): void {}

    public function hangupChannel(string $channelId): void
    {
        app(CallSimulator::class)->hangupChannel($this->server, $channelId);
    }

    public function activeChannelIds(): array
    {
        return Call::withoutGlobalScopes()
            ->where('pbx_server_id', $this->server->id)
            ->live()
            ->pluck('active_channels')
            ->flatten()
            ->values()
            ->all();
    }
}
