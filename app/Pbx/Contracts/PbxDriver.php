<?php

namespace App\Pbx\Contracts;

use App\Models\Extension;
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
