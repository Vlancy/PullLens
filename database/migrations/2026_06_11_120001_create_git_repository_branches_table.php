<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores branches discovered for a tracked repository.
     */
    public function up(): void
    {
        Schema::create('git_repository_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('name');
            $table->string('commit_sha')->nullable();
            $table->boolean('is_protected')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['git_repository_id', 'name']);
        });
    }

    /**
     * Drop the repository branches table.
     */
    public function down(): void
    {
        Schema::dropIfExists('git_repository_branches');
    }
};
