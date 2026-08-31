<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('git_provider_apps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 40)->unique();
            $table->string('name');
            $table->string('app_id')->nullable();
            $table->string('client_id');
            $table->text('client_secret');
            $table->text('webhook_secret')->nullable();
            $table->text('private_key')->nullable();
            $table->string('slug')->nullable();
            $table->timestamp('configured_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('git_provider_apps');
    }
};
