<?php

namespace Database\Factories;

use App\Authorization\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state: an owner of a fresh organization.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'status' => User::STATUS_ACTIVE,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->organization_id && $user->roles()->doesntExist()) {
                $user->assignRole(Role::Owner->value);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->syncRoles([$role->value]));
    }

    public function platformAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => null,
            'is_platform_admin' => true,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => User::STATUS_DISABLED]);
    }
}
