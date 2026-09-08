<?php

use App\Enums\AI\AiOperation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per call to an AI provider.
     *
     * Reviews already stored their own token counts, but only reviews did - disputes,
     * comment replies and assistant chats were invisible, so nobody could tell where
     * the bill actually came from. This is the single ledger for all of it.
     *
     * Cost is stored as computed, not derived at read time: provider pricing changes,
     * and a historical row must keep the price that was actually charged rather than
     * being silently restated by a later config edit.
     */
    public function up(): void
    {
        Schema::create('ai_usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('operation', 40)->default(AiOperation::PullRequestReview->value);

            $table->foreignUuid('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('provider_driver', 40)->nullable();
            $table->string('model')->nullable();

            // What the call was about. Nullable because an assistant chat or a
            // connection test belongs to no repository.
            $table->foreignUuid('git_repository_id')->nullable()->constrained('git_repositories')->nullOnDelete();
            $table->foreignUuid('pull_request_id')->nullable()->constrained('pull_requests')->nullOnDelete();
            $table->foreignUuid('pull_request_review_id')->nullable()->constrained('pull_request_reviews')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            // Cache writes cost more than ordinary input; cache reads cost far less.
            // Kept separate so the saving from caching is visible rather than blended.
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);

            // USD. 8 decimal places because a single cheap call can cost a fraction
            // of a cent and rounding it to 4 would floor thousands of calls to zero.
            $table->decimal('cost_usd', 12, 8)->nullable();
            $table->boolean('cost_is_estimated')->default(true);

            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('succeeded')->default(true);

            $table->timestamps();

            $table->index(['operation', 'created_at']);
            $table->index(['git_repository_id', 'created_at']);
            $table->index('created_at');
            $table->index('model');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
    }
};
