<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores individual AI review findings.
     *
     * The (git_repository_id, dedupe_key) composite index enables "same bug reopened"
     * detection across multiple PRs in the same repository.
     *
     * pull_request_id is denormalized here so findings can be queried without
     * always joining through pull_request_reviews.
     */
    public function up(): void
    {
        Schema::create('pull_request_review_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_review_id')->constrained('pull_request_reviews')->cascadeOnDelete();
            // Denormalized for direct per-PR and per-repo queries without extra joins
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('dedupe_key');
            $table->string('title');
            $table->string('severity', 20);
            $table->string('category', 30);
            $table->string('file', 1024);
            $table->string('file_language')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->float('confidence');
            $table->text('explanation');
            $table->text('suggested_fix');
            $table->boolean('is_posted')->default(false);
            $table->boolean('is_helpful')->nullable();
            $table->unsignedBigInteger('provider_comment_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_type', 30)->nullable();
            $table->timestamps();

            // Fast "same bug in same repo" lookup for reopened-bug reports
            $table->index(['git_repository_id', 'dedupe_key']);
            $table->index(['pull_request_id', 'severity']);
            $table->index(['pull_request_id', 'category']);
            $table->index('resolution_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request_review_findings');
    }
};
