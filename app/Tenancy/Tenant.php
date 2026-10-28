<?php

namespace App\Tenancy;

use App\Models\Organization;
use Closure;

/**
 * Holds the organization the current request or job is acting for.
 *
 * Registered as a scoped singleton so it is reset between requests and queued jobs.
 */
class Tenant
{
    private ?Organization $organization = null;

    private bool $bypassing = false;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function forget(): void
    {
        $this->organization = null;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function bypassing(): bool
    {
        return $this->bypassing;
    }

    /**
     * Run a callback in the context of the given organization, restoring the previous context afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }

    /**
     * Run a callback with tenant scoping disabled. Reserved for platform-level code.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function bypass(Closure $callback): mixed
    {
        $previous = $this->bypassing;
        $this->bypassing = true;

        try {
            return $callback();
        } finally {
            $this->bypassing = $previous;
        }
    }
}
