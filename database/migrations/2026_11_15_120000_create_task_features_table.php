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
        Schema::create('task_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->nullable()->constrained('git_repositories')->cascadeOnDelete();
            // Non-null mirror of the scope so the unique index works: NULLs are distinct in unique indexes.
            $table->string('scope_key', 60);
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->string('source', 20)->default('ai');
            $table->foreignUuid('merged_into_id')->nullable()->constrained('task_features')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_key', 'slug']);
            $table->index('merged_into_id');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_features');
    }
};
