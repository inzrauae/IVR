<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PBX servers are platform-owned infrastructure; organizations are assigned to one.
        Schema::create('pbx_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver')->default('asterisk');
            $table->string('region')->nullable();
            $table->string('sip_domain')->nullable();
            $table->string('ari_url')->nullable();
            $table->string('ari_username')->nullable();
            $table->text('ari_password')->nullable();
            $table->string('ami_host')->nullable();
            $table->unsignedInteger('ami_port')->default(5038);
            $table->string('ami_username')->nullable();
            $table->text('ami_secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('unknown');
            $table->timestamp('last_health_check_at')->nullable();
            $table->json('last_health_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pbx_servers');
    }
};
