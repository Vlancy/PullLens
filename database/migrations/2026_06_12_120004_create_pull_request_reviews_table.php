<?php

use App\Enums\GIT\ReviewTrigger;
use App\Enums\GIT\ReviewVerdict;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores AI review results from PullRequestReviewAgent.
     * One PR may have multiple reviews (re-triggered, intensity change, provider swap).
     */
    public function up(): void
    {
        Schema::create('pull_request_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->string('head_sha', 40)->nullable();
            $table->foreignUuid('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('ai_model')->nullable();
            $table->string('schema_version', 60)->default('pull_lens.pr_review.v2');
            $table->text('walkthrough');
            $table->text('diagram')->nullable();
            $table->json('detected_stack');
            $table->json('suggested_labels');
            $table->json('skipped_files');
            $table->text('summary');
            $table->string('verdict', 20)->default(ReviewVerdict::Comment->value);
            $table->string('risk_level', 20);
            $table->string('review_intensity', 20);
            $table->json('follow_up_questions');
            $table->string('triggered_by', 20)->default(ReviewTrigger::Auto->value);
            $table->boolean('posted_to_provider')->default(false);
            $table->unsignedBigInteger('provider_review_id')->nullable();
            $table->unsignedInteger('review_duration_ms')->nullable();
            $table->float('estimated_hours')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['pull_request_id', 'reviewed_at']);
            $table->index('risk_level');
            $table->index('verdict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request_reviews');
    }
};
