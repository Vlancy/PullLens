<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restore the token columns on pull_request_reviews.
     *
     * These were added together with the code that writes them, then removed again in
     * a later migration while ReviewPullRequest and the PullRequestReview model kept
     * referencing them. The result is an installation-dependent bug: databases that
     * migrated before the removal still have the columns and work, while any fresh
     * install fails on every review with "column prompt_tokens does not exist" —
     * meaning no review is ever persisted.
     *
     * Guarded by hasColumn so it is safe on both kinds of database.
     */
    public function up(): void
    {
        Schema::table('pull_request_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('pull_request_reviews', 'prompt_tokens')) {
                $table->unsignedInteger('prompt_tokens')->nullable()->after('review_duration_ms');
            }

            if (! Schema::hasColumn('pull_request_reviews', 'completion_tokens')) {
                $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            }
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::table('pull_request_reviews', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens']);
        });
    }
};
