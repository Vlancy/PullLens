<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores repositories an operator selected to track.
     */
    public function up(): void
    {
        Schema::create('git_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('git_account_id')->constrained('git_accounts')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->unsignedBigInteger('provider_repo_id');
            $table->string('owner_login');
            $table->string('owner_type', 40)->nullable();
            $table->string('name');
            $table->string('full_name');
            $table->string('default_branch')->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('web_url', 2048)->nullable();
            $table->timestamp('selected_at');
            $table->timestamps();

            $table->unique(['git_account_id', 'provider_repo_id']);
            $table->index('provider');
            $table->index('installation_id');
        });
    }

    /**
     * Drop the tracked repositories table.
     */
    public function down(): void
    {
        Schema::dropIfExists('git_repositories');
    }
};
