<?php

namespace App\Jobs;

use App\Models\Extension;
use App\Pbx\Exceptions\PbxException;
use App\Pbx\ExtensionProvisioner;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Pushes one extension's current state (active or disabled) to the PBX.
 * Failures are stored on the extension and retried with backoff.
 */
class ProvisionExtension implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [15, 60, 300];

    public function __construct(public readonly int $extensionId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("extension:{$this->extensionId}"))->releaseAfter(5)->expireAfter(120)];
    }

    public function handle(ExtensionProvisioner $provisioner, Tenant $tenant): void
    {
        // The extension may have been deleted while this job waited; nothing left to provision.
        $extension = Extension::withoutGlobalScopes()->with('organization.pbxServer')->find($this->extensionId);

        if (! $extension || ! $extension->organization) {
            return;
        }

        try {
            $tenant->runAs($extension->organization, fn () => $provisioner->sync($extension));
        } catch (PbxException) {
            // Already recorded on the extension; let the queue try again later.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        }
    }
}
