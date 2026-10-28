<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /**
     * Record an audit event for the current actor and tenant.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, ?Model $subject = null, array $metadata = [], ?Organization $organization = null): AuditLog
    {
        $request = request();
        $user = $request->user();

        $organizationId = $organization?->getKey()
            ?? app(Tenant::class)->id()
            ?? ($subject instanceof Organization ? $subject->getKey() : null)
            ?? $user?->organization_id;

        return AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => $user?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
