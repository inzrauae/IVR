<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => Organization::STATUS_ACTIVE,
            'contact_email' => fake()->companyEmail(),
        ];
    }

    /**
     * Attach a live subscription on the given (or default) package when packages are seeded.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization) {
            $package = Package::where('slug', config('pbx.default_package'))->first();

            if (! $package) {
                return;
            }

            Subscription::create([
                'organization_id' => $organization->id,
                'package_id' => $package->id,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => 'monthly',
                'price' => $package->monthly_price,
                'currency' => $package->currency,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        });
    }

    public function suspended(string $reason = 'Payment overdue'): static
    {
        return $this->state(fn () => [
            'status' => Organization::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
    }
}
