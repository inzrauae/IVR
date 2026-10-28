<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data every environment needs (including tests).
 */
class BaseDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PackageSeeder::class,
        ]);
    }
}
