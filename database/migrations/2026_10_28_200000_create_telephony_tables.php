<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phases 11–13: trunks, numbers, routing, IVR, queues, ring groups, conferences,
 * recordings, voicemail, contacts and webhooks.
 *
 * Destinations are stored as "<type>:<value>" strings (see App\Pbx\Routing\Destination).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Globals last pushed to the PBX, so keys that disappear can be cleared.
            $table->json('pbx_routing_keys')->nullable();
            $table->timestamp('pbx_routing_pushed_at')->nullable();
            $table->text('pbx_routing_error')->nullable();
        });

        Schema::create('sip_trunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('host');
            $table->unsignedInteger('port')->default(5060);
            $table->string('transport', 10)->default('udp');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->boolean('register')->default(false);
            $table->string('from_domain')->nullable();
            $table->string('caller_id')->nullable();
            $table->unsignedSmallInteger('max_channels')->default(0);
            $table->string('codecs')->default('ulaw,alaw');
            $table->string('status')->default('active');
            $table->string('provisioning_status')->default('pending');
            $table->text('provisioning_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('did_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sip_trunk_id')->nullable()->constrained()->nullOnDelete();
            // Digits only (no +); a DID routes to exactly one tenant.
            $table->string('number', 20)->unique();
            $table->string('description')->nullable();
            $table->string('destination')->nullable();
            // Used outside business hours / on holidays; null = same as destination.
            $table->string('after_hours_destination')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('outbound_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sip_trunk_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Asterisk-style pattern without the leading "_", e.g. 07XXXXXXXX, 00X., +X.
            $table->string('pattern', 50);
            $table->unsignedTinyInteger('strip_digits')->default(0);
            $table->string('prepend', 20)->nullable();
            $table->unsignedSmallInteger('priority')->default(10);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'priority']);
        });

        Schema::create('ivr_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 10);
            $table->string('name');
            $table->string('greeting_path')->nullable();
            $table->unsignedTinyInteger('timeout')->default(5);
            $table->unsignedTinyInteger('max_retries')->default(2);
            // [{digit: "1", destination: "extension:101"}, ...]
            $table->json('options');
            $table->string('timeout_destination')->nullable();
            $table->string('invalid_destination')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('ring_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 10);
            $table->string('name');
            $table->string('strategy')->default('ringall');
            $table->unsignedSmallInteger('ring_timeout')->default(20);
            // Ordered extension ids (order matters for the sequential strategy).
            $table->json('members');
            $table->string('fallback_destination')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('call_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 10);
            $table->string('name');
            $table->string('strategy')->default('rrmemory');
            $table->unsignedSmallInteger('agent_timeout')->default(15);
            $table->unsignedSmallInteger('wrapup_time')->default(5);
            $table->unsignedSmallInteger('max_wait')->default(300);
            $table->string('fallback_destination')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('call_queue_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('penalty')->default(0);
            $table->boolean('paused')->default(false);

            $table->unique(['call_queue_id', 'extension_id']);
        });

        Schema::create('conferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 10);
            $table->string('name');
            $table->string('pin', 10)->nullable();
            $table->unsignedSmallInteger('max_members')->default(10);
            $table->boolean('record')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pbx_server_id')->nullable()->constrained()->nullOnDelete();
            // Stored recording name on the PBX (ARI /recordings/stored/{name}).
            $table->string('name');
            $table->unsignedInteger('duration')->nullable();
            $table->timestamps();

            $table->unique(['pbx_server_id', 'name']);
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('voicemail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extension_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pbx_server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('caller_number')->nullable();
            $table->string('caller_name')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->timestamp('heard_at')->nullable();
            $table->timestamps();

            $table->unique(['pbx_server_id', 'name']);
            $table->index(['organization_id', 'extension_id']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'phone']);
            $table->index(['organization_id', 'mobile']);
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->json('events');
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'created_at']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('did_number_id')->nullable()->after('to_extension_id')->constrained()->nullOnDelete();
        });

        // Asterisk realtime reads queues from these views (see docs/asterisk/extconfig.conf).
        DB::statement(<<<'SQL'
            CREATE VIEW asterisk_queues AS
            SELECT 'o' || organization_id || '_q' || number AS name,
                   strategy, agent_timeout AS timeout, wrapup_time AS wrapuptime,
                   'default' AS musiconhold, 'yes' AS joinempty, 'no' AS leavewhenempty,
                   'yes' AS ringinuse
            FROM call_queues
        SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW asterisk_queue_members AS
            SELECT m.id AS uniqueid,
                   'o' || q.organization_id || '_q' || q.number AS queue_name,
                   'PJSIP/' || e.sip_username AS interface,
                   e.name AS membername,
                   'PJSIP/' || e.sip_username AS state_interface,
                   m.penalty, CASE WHEN m.paused THEN 1 ELSE 0 END AS paused
            FROM call_queue_members m
            JOIN call_queues q ON q.id = m.call_queue_id
            JOIN extensions e ON e.id = m.extension_id
            WHERE e.status = 'active'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS asterisk_queue_members');
        DB::statement('DROP VIEW IF EXISTS asterisk_queues');

        Schema::table('calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('did_number_id');
        });

        foreach (['webhook_deliveries', 'webhooks', 'contacts', 'voicemail_messages', 'recordings', 'conferences',
            'call_queue_members', 'call_queues', 'ring_groups', 'ivr_menus', 'outbound_routes', 'did_numbers', 'sip_trunks'] as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['pbx_routing_keys', 'pbx_routing_pushed_at', 'pbx_routing_error']);
        });
    }
};
