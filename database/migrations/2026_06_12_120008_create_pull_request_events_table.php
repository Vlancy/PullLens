<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the event log table for pull request lifecycle events.
     * Drives timeline views in the dashboard without requiring re-fetches from the provider.
     * Event types: opened, closed, reopened, merged, pushed, labeled, unlabeled,
     *              review_requested, review_submitted, comment_added,
     *              pull_lens_review_completed, pull_lens_reply_posted.
     */
    public function up(): void
    {
        Schema::create('pull_request_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            // Denormalized to query all events for a repo without joining pull_requests
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('event_type', 60);
            $table->string('actor_login')->nullable();
            $table->string('actor_type', 20)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['pull_request_id', 'occurred_at']);
            $table->index(['git_repository_id', 'event_type', 'occurred_at']);
            $table->index(['git_repository_id', 'actor_login', 'occurred_at']);
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_request_events');
    }
};
