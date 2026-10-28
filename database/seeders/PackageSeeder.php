<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\PackageFeature as F;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'attributes' => [
                    'slug' => 'starter',
                    'name' => 'Starter',
                    'description' => 'For small teams getting started with a cloud phone system.',
                    'monthly_price' => 7500,
                    'annual_price' => 81000,
                    'max_users' => 3,
                    'max_extensions' => 3,
                    'max_concurrent_calls' => 4,
                    'recording_storage_mb' => 5120,
                ],
                'features' => [F::BASIC_IVR, F::CALL_TRANSFERS, F::CALL_RECORDING, F::LIVE_MONITORING, F::MANAGER_REPORTS, F::DEDICATED_DID],
            ],
            [
                'attributes' => [
                    'slug' => 'business',
                    'name' => 'Business',
                    'description' => 'Growing teams with queues and call center needs.',
                    'monthly_price' => 19500,
                    'annual_price' => 210000,
                    'max_users' => 15,
                    'max_extensions' => 15,
                    'max_concurrent_calls' => 15,
                    'recording_storage_mb' => 51200,
                ],
                'features' => [F::BASIC_IVR, F::CALL_TRANSFERS, F::CALL_RECORDING, F::LIVE_MONITORING, F::MANAGER_REPORTS, F::DEDICATED_DID],
            ],
        ];

        foreach ($packages as $definition) {
            $package = Package::updateOrCreate(
                ['slug' => $definition['attributes']['slug']],
                $definition['attributes'] + ['currency' => 'LKR', 'trial_days' => 14, 'is_active' => true],
            );

            foreach ($definition['features'] as $feature) {
                $package->features()->firstOrCreate(['feature' => $feature]);
            }
        }
    }
}
