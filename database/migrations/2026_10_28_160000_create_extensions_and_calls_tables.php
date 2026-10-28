<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pbx_servers', function (Blueprint $table) {
            // Public WebSocket URL browsers use for the web phone, e.g. wss://sip.example.com:8089/ws
            $table->string('wss_url')->nullable()->after('sip_domain');
        });

        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 10);
            $table->string('name');
            // PJSIP endpoint/auth/aor id on the PBX; globally unique across tenants.
            $table->string('sip_username')->unique();
            $table->text('sip_password');
            $table->string('caller_id_name')->nullable();
            $table->string('caller_id_number')->nullable();
            $table->boolean('webrtc_enabled')->default(true);
            $table->boolean('voicemail_enabled')->default(true);
            $table->boolean('do_not_disturb')->default(false);
            $table->unsignedSmallInteger('ring_timeout')->nullable();
            $table->string('status')->default('active');
            $table->string('provisioning_status')->default('pending');
            $table->text('provisioning_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->boolean('registered')->default(false);
            $table->string('registered_contact')->nullable();
            $table->string('registered_user_agent')->nullable();
            $table->timestamp('registration_changed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pbx_server_id')->nullable()->constrained()->nullOnDelete();
            // Asterisk Linkedid: shared by every channel of one call.
            $table->string('linked_id');
            $table->string('direction');
            $table->string('status')->index();
            $table->string('from_number')->nullable();
            $table->string('from_name')->nullable();
            $table->string('to_number')->nullable();
            $table->foreignId('from_extension_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->foreignId('to_extension_id')->nullable()->constrained('extensions')->nullOnDelete();
            // Uniqueids of channels still up; the call ends when this empties.
            $table->json('active_channels');
            $table->string('hangup_cause')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedInteger('talk_time')->nullable();
            $table->timestamps();

            $table->unique(['pbx_server_id', 'linked_id']);
            $table->index(['organization_id', 'started_at']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('call_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('channel')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['call_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_events');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('extensions');

        Schema::table('pbx_servers', function (Blueprint $table) {
            $table->dropColumn('wss_url');
        });
    }
};
