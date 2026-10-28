<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::table('extensions', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // Forwarding destinations: an internal extension number or an external number.
            $table->string('forward_always', 20)->nullable()->after('ring_timeout');
            $table->string('forward_busy', 20)->nullable()->after('forward_always');
            $table->string('forward_no_answer', 20)->nullable()->after('forward_busy');
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['forward_always', 'forward_busy', 'forward_no_answer']);
        });

        Schema::dropIfExists('departments');
    }
};
