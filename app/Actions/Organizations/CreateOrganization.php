<?php

namespace App\Actions\Organizations;

use App\Authorization\Role;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboards a new organization: the tenant record, its owner, and a trial subscription.
 */
class CreateOrganization
{
    /**
     * @param  array{name: string, email: string, password: string}  $owner
     */
    public function handle(string $organizationName, array $owner, ?Package $package = null): User
    {
        $package ??= Package::where('slug', config('pbx.default_package'))->where('is_active', true)->firstOrFail();

        return DB::transaction(function () use ($organizationName, $owner, $package) {
            $organization = Organization::create([
                'name' => $organizationName,
                'slug' => $this->uniqueSlug($organizationName),
                'status' => Organization::STATUS_TRIAL,
                'currency' => $package->currency,
                'contact_email' => $owner['email'],
            ]);

            Subscription::create([
                'organization_id' => $organization->id,
                'package_id' => $package->id,
                'status' => Subscription::STATUS_TRIALING,
                'billing_cycle' => 'monthly',
                'price' => $package->monthly_price,
                'currency' => $package->currency,
                'trial_ends_at' => now()->addDays($package->trial_days),
                'current_period_start' => now(),
                'current_period_end' => now()->addDays($package->trial_days),
            ]);

            $user = new User($owner);
            $user->organization()->associate($organization);
            $user->save();
            $user->assignRole(Role::Owner->value);

            Audit::record('organization.created', $organization, ['package' => $package->slug], $organization);

            return $user;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
