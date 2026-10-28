<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts tenant-owned models to the current organization.
 *
 * Fails closed: with no tenant context (and no explicit bypass) the query matches nothing.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);

        if ($tenant->bypassing()) {
            return;
        }

        if ($tenant->check()) {
            $builder->where($model->qualifyColumn('organization_id'), $tenant->id());

            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
