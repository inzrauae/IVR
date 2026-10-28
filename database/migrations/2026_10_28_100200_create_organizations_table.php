<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('trial')->index();
            $table->foreignId('pbx_server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('timezone')->default('Asia/Colombo');
            $table->string('locale', 10)->default('en');
            $table->char('currency', 3)->default('LKR');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->string('status')->default('active')->after('is_platform_admin');
            $table->timestamp('last_login_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['is_platform_admin', 'status', 'last_login_at']);
        });

        Schema::dropIfExists('organizations');
    }
};
