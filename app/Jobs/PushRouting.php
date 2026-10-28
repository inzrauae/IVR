<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\Routing\RoutingPublisher;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Compiles and pushes one organization's routing (DIDs, IVRs, groups, queues, routes...).
 * The job reads the latest configuration when it runs, so several edits in a row collapse
 * into one push; the unique lock is released when processing starts so a later edit still
 * gets its own push.
 */
class PushRouting implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $organizationId) {}

    /**
     * Queue a push for the organization in the current tenant context.
     */
    public static function forCurrentTenant(): void
    {
        if ($id = app(Tenant::class)->id()) {
            static::dispatch($id);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("routing:{$this->organizationId}"))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(RoutingPublisher $publisher, Tenant $tenant): void
    {
        $organization = Organization::with('pbxServer')->find($this->organizationId);

        if (! $organization) {
            return;
        }

        try {
            $tenant->runAs($organization, fn () => $publisher->publish($organization));
        } catch (PbxException) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        }
    }
}
