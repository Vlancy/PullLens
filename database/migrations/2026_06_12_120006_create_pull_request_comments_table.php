<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores PR comment threads fetched from the provider.
     * Both human comments and PullLens-generated comments are stored here.
     * is_pull_lens distinguishes which rows originated from this system.
     */
    public function up(): void
    {
        Schema::create('pull_request_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            // Null when the comment is not linked to a specific finding
            $table->foreignUuid('pull_request_review_finding_id')
                ->nullable()
                ->constrained('pull_request_review_findings')
                ->nullOnDelete();
            $table->unsignedBigInteger('provider_comment_id');
            $table->unsignedBigInteger('provider_in_reply_to_id')->nullable();
            $table->string('author_login');
            $table->string('author_type', 20)->default('user');
            $table->text('body');
            $table->string('comment_type', 20)->default('issue_comment');
            $table->boolean('is_pull_lens')->default(false);
            $table->timestamp('provider_created_at');
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['pull_request_id', 'provider_comment_id']);
            $table->index(['pull_request_id', 'is_pull_lens']);
            $table->index('provider_in_reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request_comments');
    }
};
