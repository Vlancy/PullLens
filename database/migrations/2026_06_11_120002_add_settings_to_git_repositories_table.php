<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-repository review settings that control how PullLens reviews PRs.
     */
    public function up(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->boolean('reviews_enabled')->default(true)->after('selected_at');
            $table->boolean('auto_review_on_open')->default(true)->after('reviews_enabled');
            $table->boolean('auto_approve')->default(false)->after('auto_review_on_open');
            $table->boolean('auto_apply_labels')->default(false)->after('auto_approve');
            $table->boolean('allow_comment_replies')->default(true)->after('auto_apply_labels');
            $table->boolean('auto_merge')->default(false)->after('allow_comment_replies');
            $table->string('auto_merge_method', 20)->default('merge')->after('auto_merge');
            $table->string('review_language', 50)->default('English')->after('auto_merge_method');
            $table->json('base_branches')->nullable()->after('review_language');
            $table->json('tracked_branches')->nullable()->after('base_branches');
        });
    }

    /**
     * Drop the per-repository review settings columns.
     */
    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->dropColumn([
                'reviews_enabled',
                'auto_review_on_open',
                'auto_approve',
                'auto_apply_labels',
                'allow_comment_replies',
                'auto_merge',
                'auto_merge_method',
                'review_language',
                'base_branches',
                'tracked_branches',
            ]);
        });
    }
};
