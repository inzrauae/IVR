<?php

namespace App\Pbx;

use App\Models\Extension;
use App\Models\PbxServer;
use App\Pbx\Exceptions\PbxException;
use App\Support\Audit;

/**
 * Brings the PBX in line with an extension record and stores the outcome on the record,
 * so the portal can show failures and offer a retry.
 */
class ExtensionProvisioner
{
    public function __construct(private readonly PbxManager $pbx) {}

    /**
     * Push an active extension to its organization's PBX, or remove a disabled one.
     *
     * @throws PbxException when the PBX rejects the change (the failure is recorded first)
     */
    public function sync(Extension $extension): void
    {
        $server = $extension->organization->pbxServer;

        try {
            if (! $server) {
                throw new PbxException('No PBX server is assigned to this organization. Contact support.');
            }

            $driver = $this->pbx->driver($server);

            if ($extension->isActive()) {
                $driver->provisionExtension($extension);
            } else {
                $driver->deprovisionExtension($extension->sip_username);
            }
        } catch (PbxException $e) {
            $alreadyFailing = $extension->provisioning_status === Extension::PROVISION_FAILED;

            $extension->forceFill([
                'provisioning_status' => Extension::PROVISION_FAILED,
                'provisioning_error' => $e->getMessage(),
            ])->save();

            if (! $alreadyFailing) {
                Audit::record('extension.provisioning_failed', $extension, ['error' => $e->getMessage()], $extension->organization);
            }

            throw $e;
        }

        $extension->forceFill([
            'provisioning_status' => Extension::PROVISION_OK,
            'provisioning_error' => null,
            'provisioned_at' => now(),
        ])->save();
    }

    /**
     * @throws PbxException
     */
    public function remove(PbxServer $server, string $sipUsername): void
    {
        $this->pbx->driver($server)->deprovisionExtension($sipUsername);
    }
}
