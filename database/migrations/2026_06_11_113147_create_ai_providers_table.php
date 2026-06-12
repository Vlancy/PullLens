<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create encrypted AI provider settings for Laravel AI drivers.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider_driver', 50);
            $table->string('name')->unique();
            $table->text('credentials')->nullable();
            $table->string('base_url')->nullable();
            $table->string('default_model')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['is_enabled', 'is_default']);
            $table->index('provider_driver');
        });
    }

    /**
     * Drop encrypted AI provider settings.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
