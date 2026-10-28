<?php

namespace App\Pbx\Routing;

use App\Models\Organization;
use App\Models\PbxServer;
use App\Models\SipTrunk;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\PbxManager;
use App\Support\Audit;

/**
 * Pushes an organization's compiled routing to its PBX and records the outcome, and keeps
 * SIP trunks in line with their records.
 */
class RoutingPublisher
{
    public function __construct(private readonly PbxManager $pbx, private readonly RoutingCompiler $compiler) {}

    /**
     * @throws PbxException
     */
    public function publish(Organization $organization): void
    {
        $server = $organization->pbxServer;

        try {
            if (! $server) {
                throw new PbxException('No PBX server is assigned to this organization. Contact support.');
            }

            $values = $this->compiler->compile($organization);
            // Keys pushed last time but no longer produced (a deleted ring group, a removed DID...).
            $stale = array_diff($organization->pbx_routing_keys ?? [], array_keys($values));

            $this->pbx->driver($server)->setGlobals($values + array_fill_keys($stale, ''));
        } catch (PbxException $e) {
            $alreadyFailing = $organization->pbx_routing_error !== null;
            $organization->forceFill(['pbx_routing_error' => $e->getMessage()])->save();

            if (! $alreadyFailing) {
                Audit::record('routing.publish_failed', $organization, ['error' => $e->getMessage()], $organization);
            }

            throw $e;
        }

        $organization->forceFill([
            'pbx_routing_keys' => array_keys($values),
            'pbx_routing_pushed_at' => now(),
            'pbx_routing_error' => null,
        ])->save();
    }

    /**
     * Clear globals from a server the organization no longer uses.
     *
     * @param  list<string>  $keys
     *
     * @throws PbxException
     */
    public function clear(PbxServer $server, array $keys): void
    {
        if ($keys) {
            $this->pbx->driver($server)->setGlobals(array_fill_keys($keys, ''));
        }
    }

    /**
     * @throws PbxException
     */
    public function syncTrunk(SipTrunk $trunk): void
    {
        $server = $trunk->organization->pbxServer;

        try {
            if (! $server) {
                throw new PbxException('No PBX server is assigned to this organization. Contact support.');
            }

            $driver = $this->pbx->driver($server);
            $trunk->isActive() ? $driver->provisionTrunk($trunk) : $driver->deprovisionTrunk($trunk->endpointId());
        } catch (PbxException $e) {
            $trunk->forceFill(['provisioning_status' => 'failed', 'provisioning_error' => $e->getMessage()])->save();

            throw $e;
        }

        $trunk->forceFill(['provisioning_status' => 'provisioned', 'provisioning_error' => null, 'provisioned_at' => now()])->save();
    }
}
