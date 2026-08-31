<?php

use App\Enums\GIT\TaskType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table holding the units of work the reviewer identified in a PR.
     *
     * One pull request yields one or more tasks, so a PR that adds an endpoint and
     * also fixes a bug is reported as two distinct pieces of work rather than one
     * blurred line item.
     *
     * `git_repository_id` and `author_login` are denormalised from the pull request
     * on purpose: the monthly report groups by developer across every repository, and
     * denormalising keeps that a single indexed scan instead of a three-table join.
     * `author_login` is also a point-in-time record — it must not change if the PR is
     * later edited or transferred.
     */
    public function up(): void
    {
        Schema::create('pull_request_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_review_id')->constrained('pull_request_reviews')->cascadeOnDelete();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();

            // Stable identifier the model produces for a task, so re-reviewing a PR
            // updates its tasks in place rather than accumulating near-duplicates.
            $table->string('dedupe_key');

            $table->string('title');
            $table->string('type', 30)->default(TaskType::Chore->value);
            $table->text('description')->nullable();
            $table->float('estimated_hours')->nullable();

            // Paths this task touched, for showing what it actually covered.
            $table->json('files')->nullable();

            $table->string('author_login')->nullable();
            $table->string('author_name')->nullable();
            $table->string('author_avatar_url', 2048)->nullable();

            // Mirrors the PR's merge time so the report can ask "what shipped in
            // August" without joining back to pull_requests on every row.
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->unique(['pull_request_id', 'dedupe_key']);
            $table->index(['author_login', 'delivered_at']);
            $table->index(['git_repository_id', 'delivered_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request_tasks');
    }
};
