<?php

namespace Database\Seeders;

use App\Actions\Organizations\CreateOrganization;
use App\Models\PbxServer;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with reference data plus local demo accounts.
     */
    public function run(): void
    {
        $this->call(BaseDataSeeder::class);

        if (app()->isProduction()) {
            return;
        }

        User::factory()->platformAdmin()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@example.com',
        ]);

        $server = PbxServer::factory()->create([
            'name' => 'Local simulator',
            'driver' => 'simulator',
            'sip_domain' => null,
            'ari_url' => null,
            'ari_username' => null,
            'ari_password' => null,
        ]);

        $owner = app(CreateOrganization::class)->handle('Demo Company', [
            'name' => 'Demo Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $owner->organization->forceFill(['pbx_server_id' => $server->id])->save();
    }
}
