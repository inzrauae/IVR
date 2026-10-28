<?php

namespace App\Jobs;

use App\Models\PbxServer;
use App\Pbx\ExtensionProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Removes a deleted (or moved) extension from a PBX. Carries plain values because the
 * extension row is usually gone by the time this runs. Leftovers are pruned by pbx:sync --prune.
 */
class DeprovisionExtension implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $pbxServerId, public readonly string $sipUsername) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("sip:{$this->sipUsername}"))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(ExtensionProvisioner $provisioner): void
    {
        $server = PbxServer::find($this->pbxServerId);

        if ($server) {
            $provisioner->remove($server, $this->sipUsername);
        }
    }
}
