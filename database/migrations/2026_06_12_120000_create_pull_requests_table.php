<?php

use App\Enums\GIT\PullRequestState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores pull requests fetched from tracked repositories.
     */
    public function up(): void
    {
        Schema::create('pull_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->unsignedBigInteger('provider_pr_id');
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('state', 20)->default(PullRequestState::Open->value);
            $table->boolean('is_draft')->default(false);
            $table->string('author_login');
            $table->string('author_name')->nullable();
            $table->string('author_avatar_url', 2048)->nullable();
            $table->string('source_branch');
            $table->string('target_branch');
            $table->string('web_url', 2048)->nullable();
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->unsignedInteger('changed_files_count')->default(0);
            $table->unsignedInteger('commits_count')->default(0);
            $table->json('labels')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->string('merged_by_login')->nullable();
            $table->string('merge_commit_sha', 40)->nullable();
            $table->string('head_sha', 40)->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['git_repository_id', 'provider_pr_id']);
            $table->index(['git_repository_id', 'state']);
            $table->index(['git_repository_id', 'author_login']);
            $table->index('opened_at');
            $table->index('merged_at');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_requests');
    }
};
