<?php

use App\Enums\GIT\MergeMethod;
use App\Enums\GIT\ReviewIntensity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores repositories an operator selected to track.
     */
    public function up(): void
    {
        Schema::create('git_repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('git_account_id')->constrained('git_accounts')->cascadeOnDelete();
            $table->foreignUuid('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->unsignedBigInteger('provider_repo_id');
            $table->string('provider', 40);
            $table->string('ai_model')->nullable();
            $table->string('owner_login');
            $table->string('owner_type', 40)->nullable();
            $table->string('name');
            $table->string('full_name');
            $table->string('default_branch')->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('web_url', 2048)->nullable();
            $table->boolean('reviews_enabled')->default(true);
            $table->string('review_intensity', 20)->default(ReviewIntensity::Balanced->value);
            $table->boolean('auto_review_on_open')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->boolean('auto_apply_labels')->default(false);
            $table->boolean('allow_comment_replies')->default(true);
            $table->boolean('auto_merge')->default(false);
            $table->string('auto_merge_method', 20)->default(MergeMethod::Merge->value);
            $table->string('review_language', 2)->default('en');
            $table->json('base_branches')->nullable();
            $table->json('tracked_branches')->nullable();
            $table->timestamps();

            $table->unique(['git_account_id', 'provider_repo_id']);
            $table->index('provider');
            $table->index('installation_id');
        });
    }

    /**
     * Drop the tracked repositories table.
     */
    public function down(): void
    {
        Schema::dropIfExists('git_repositories');
    }
};
