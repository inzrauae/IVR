<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'currency' => 'LKR',
            'monthly_price' => 5000,
            'annual_price' => 54000,
            'max_users' => 5,
            'trial_days' => 14,
            'is_active' => true,
        ];
    }
}
