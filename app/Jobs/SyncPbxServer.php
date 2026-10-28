<?php

namespace App\Jobs;

use App\Models\Extension;
use App\Models\Organization;
use App\Models\PbxServer;
use App\Models\SipTrunk;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\ExtensionProvisioner;
use App\Pbx\PbxManager;
use App\Pbx\Routing\RoutingPublisher;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-pushes every extension assigned to a PBX server. Run after the server restarts (dynamic
 * configuration and globals live in memory there) and on demand with pbx:sync.
 */
class SyncPbxServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public readonly int $pbxServerId, public readonly bool $prune = false) {}

    public function uniqueId(): string
    {
        return (string) $this->pbxServerId;
    }

    /**
     * @return array{synced: int, failed: int, pruned: int}
     */
    public function handle(ExtensionProvisioner $provisioner, PbxManager $pbx, Tenant $tenant, RoutingPublisher $publisher): array
    {
        $result = ['synced' => 0, 'failed' => 0, 'pruned' => 0];
        $server = PbxServer::find($this->pbxServerId);

        if (! $server) {
            return $result;
        }

        $known = [];

        Extension::withoutGlobalScopes()
            ->with('organization.pbxServer')
            ->whereHas('organization', fn ($q) => $q->where('pbx_server_id', $server->id))
            ->orderBy('id')
            ->each(function (Extension $extension) use ($provisioner, $tenant, &$result, &$known) {
                $known[$extension->sip_username] = true;

                try {
                    $tenant->runAs($extension->organization, fn () => $provisioner->sync($extension));
                    $result['synced']++;
                } catch (PbxException) {
                    $result['failed']++;
                }
            });

        // Trunks and routing globals live on the same server and are lost with it.
        SipTrunk::withoutGlobalScopes()
            ->with('organization.pbxServer')
            ->whereHas('organization', fn ($q) => $q->where('pbx_server_id', $server->id))
            ->each(function (SipTrunk $trunk) use ($publisher, $tenant, &$result) {
                try {
                    $tenant->runAs($trunk->organization, fn () => $publisher->syncTrunk($trunk));
                    $result['synced']++;
                } catch (PbxException) {
                    $result['failed']++;
                }
            });

        Organization::with('pbxServer')->where('pbx_server_id', $server->id)->each(function (Organization $organization) use ($publisher, $tenant, &$result) {
            try {
                $tenant->runAs($organization, fn () => $publisher->publish($organization));
            } catch (PbxException) {
                $result['failed']++;
            }
        });

        if ($this->prune) {
            foreach ($pbx->driver($server)->provisionedExtensionIds() as $sipUsername) {
                if (! isset($known[$sipUsername])) {
                    $provisioner->remove($server, $sipUsername);
                    $result['pruned']++;
                }
            }
        }

        return $result;
    }
}
