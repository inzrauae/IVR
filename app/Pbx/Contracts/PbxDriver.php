<?php

namespace App\Pbx\Contracts;

use App\Models\Extension;
use App\Models\SipTrunk;
use App\Pbx\Data\PbxHealth;
use App\Pbx\Exceptions\PbxException;

/**
 * A connection to one PBX server.
 */
interface PbxDriver
{
    public function health(): PbxHealth;

    /**
     * Create or update the endpoint, auth, AOR and call-handling settings for an extension,
     * then confirm the PBX holds them. Idempotent.
     *
     * @throws PbxException
     */
    public function provisionExtension(Extension $extension): void;

    /**
     * Remove an extension's objects from the PBX by its SIP username. Missing objects are not an error.
     *
     * @throws PbxException
     */
    public function deprovisionExtension(string $sipUsername): void;

    /**
     * SIP usernames of every tenant extension currently configured on the PBX.
     *
     * @return list<string>
     *
     * @throws PbxException
     */
    public function provisionedExtensionIds(): array;

    /**
     * Set dialplan globals; an empty value clears one.
     *
     * @param  array<string, string>  $values
     *
     * @throws PbxException
     */
    public function setGlobals(array $values): void;

    /**
     * Create or update a SIP trunk's endpoint, AOR, auth, IP match and (optional) registration.
     *
     * @throws PbxException
     */
    public function provisionTrunk(SipTrunk $trunk): void;

    /**
     * @throws PbxException
     */
    public function deprovisionTrunk(string $endpointId): void;

    /**
     * Ring a supervisor's extension and, once answered, connect it to a live channel to
     * listen, whisper to the agent, or barge in (mode: listen|whisper|barge).
     *
     * @throws PbxException
     */
    public function spy(string $supervisorSipId, string $targetChannel, string $mode): void;

    /**
     * WAV audio of a recording stored on the PBX (call recordings and voicemail).
     *
     * @throws PbxException
     */
    public function recordingAudio(string $name): string;

    /**
     * Missing recordings are not an error.
     *
     * @throws PbxException
     */
    public function deleteRecording(string $name): void;

    /**
     * @throws PbxException
     */
    public function hangupChannel(string $channelId): void;

    /**
     * Ids of every channel currently up on the PBX (used to reconcile after reconnecting).
     *
     * @return list<string>
     *
     * @throws PbxException
     */
    public function activeChannelIds(): array;
}
