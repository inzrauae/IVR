<?php

namespace App\Jobs;

use App\Models\SipTrunk;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\Routing\RoutingPublisher;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProvisionTrunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $trunkId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("trunk:{$this->trunkId}"))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(RoutingPublisher $publisher, Tenant $tenant): void
    {
        $trunk = SipTrunk::withoutGlobalScopes()->with('organization.pbxServer')->find($this->trunkId);

        if (! $trunk || ! $trunk->organization) {
            return;
        }

        try {
            $tenant->runAs($trunk->organization, fn () => $publisher->syncTrunk($trunk));
        } catch (PbxException) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        }
    }
}
