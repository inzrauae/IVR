<?php

namespace Database\Factories;

use App\Models\PbxServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PbxServer>
 */
class PbxServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'PBX '.fake()->unique()->city(),
            'driver' => 'asterisk',
            'region' => 'ap-south',
            'sip_domain' => fake()->domainName(),
            'ari_url' => 'http://pbx.test:8088/ari',
            'ari_username' => 'laravel',
            'ari_password' => 'secret',
            'is_active' => true,
        ];
    }
}
