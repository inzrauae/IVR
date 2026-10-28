<?php

namespace Database\Factories;

use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extension>
 */
class ExtensionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'number' => (string) fake()->unique()->numberBetween(100, 899),
            'name' => fake()->name(),
            'status' => 'active',
        ];
    }

    public function provisioned(): static
    {
        return $this->afterCreating(fn (Extension $e) => $e->forceFill([
            'provisioning_status' => Extension::PROVISION_OK,
            'provisioned_at' => now(),
        ])->save());
    }
}
