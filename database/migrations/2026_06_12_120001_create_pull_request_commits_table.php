<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores individual commits belonging to a pull request.
     * Each commit may have a different author, supporting multi-contributor PR analysis.
     */
    public function up(): void
    {
        Schema::create('pull_request_commits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->char('sha', 40);
            $table->char('short_sha', 7);
            $table->text('message');
            $table->string('author_login')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->string('author_avatar_url', 2048)->nullable();
            $table->timestamp('committed_at');
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('changed_files_count')->default(0);
            $table->timestamps();

            $table->unique(['pull_request_id', 'sha']);
            $table->index(['pull_request_id', 'author_login']);
            $table->index('committed_at');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_request_commits');
    }
};
