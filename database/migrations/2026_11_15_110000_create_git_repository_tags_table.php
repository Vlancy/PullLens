<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apply the schema change.
     */
    public function up(): void
    {
        Schema::create('git_repository_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('kind', 20);
            $table->string('source', 20);
            $table->boolean('dismissed')->default(false);
            $table->timestamps();

            $table->unique(['git_repository_id', 'name']);
        });

        Schema::table('git_repositories', function (Blueprint $table) {
            $table->timestamp('profile_refreshed_at')->nullable();
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->dropColumn('profile_refreshed_at');
        });

        Schema::dropIfExists('git_repository_tags');
    }
};
