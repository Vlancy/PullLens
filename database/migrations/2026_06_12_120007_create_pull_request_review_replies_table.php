<?php

use App\Enums\GIT\CommentReplyType;
use App\Enums\GIT\CommentSuggestedResolution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores AI reply results from PullRequestCommentReplyAgent.
     * Linked to the comment that triggered the reply, not the finding directly —
     * the addressed_finding_key maps back to pull_request_review_findings.dedupe_key.
     */
    public function up(): void
    {
        Schema::create('pull_request_review_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_comment_id')->constrained('pull_request_comments')->cascadeOnDelete();
            $table->foreignUuid('pull_request_review_id')->nullable()->constrained('pull_request_reviews')->nullOnDelete();
            $table->foreignUuid('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('ai_model')->nullable();
            $table->string('schema_version', 60)->default('pull_lens.comment_reply.v1');
            $table->text('reply');
            $table->string('reply_type', 30)->default(CommentReplyType::Clarification->value);
            $table->string('addressed_finding_key')->nullable();
            $table->float('confidence');
            $table->boolean('requires_author_action')->default(false);
            $table->string('suggested_resolution', 20)->default(CommentSuggestedResolution::KeepOpen->value);
            $table->boolean('posted_to_provider')->default(false);
            $table->unsignedBigInteger('provider_comment_id')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['pull_request_comment_id']);
            $table->index('addressed_finding_key');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_request_review_replies');
    }
};
