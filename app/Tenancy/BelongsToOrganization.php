<?php

namespace App\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marks a model as tenant-owned: queries are scoped to the current organization and
 * new records are stamped with it automatically.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if ($model->organization_id !== null) {
                return;
            }

            $tenant = app(Tenant::class);

            if (! $tenant->check()) {
                throw new LogicException('Cannot create '.static::class.' without an organization context.');
            }

            $model->organization_id = $tenant->id();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
