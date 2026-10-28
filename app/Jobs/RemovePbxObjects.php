<?php

namespace App\Jobs;

use App\Models\PbxServer;
use App\Pbx\PbxManager;
use App\Pbx\Routing\RoutingPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes what an organization left behind on a PBX server: a deleted trunk, or every
 * global after the organization moved to another server. Carries only ids and names, since
 * the records may already be gone.
 */
class RemovePbxObjects implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    /**
     * @param  list<string>  $globals
     * @param  list<string>  $trunkIds
     */
    public function __construct(public readonly int $pbxServerId, public readonly array $globals = [], public readonly array $trunkIds = []) {}

    public function handle(PbxManager $pbx, RoutingPublisher $publisher): void
    {
        $server = PbxServer::find($this->pbxServerId);

        if (! $server) {
            return;
        }

        foreach ($this->trunkIds as $id) {
            $pbx->driver($server)->deprovisionTrunk($id);
        }

        $publisher->clear($server, $this->globals);
    }
}
