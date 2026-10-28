<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->char('currency', 3)->default('LKR');
            $table->decimal('monthly_price', 12, 2);
            $table->decimal('annual_price', 12, 2)->nullable();
            // Null limits mean "unlimited".
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_extensions')->nullable();
            $table->unsignedInteger('max_concurrent_calls')->nullable();
            $table->unsignedInteger('recording_storage_mb')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->decimal('overage_per_extension', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('package_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('feature');
            $table->timestamps();

            $table->unique(['package_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_features');
        Schema::dropIfExists('packages');
    }
};
