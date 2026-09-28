<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let findings come from the secret scan as well as from an AI review.
     *
     * A gitleaks finding has no review behind it, so the review key becomes optional
     * and the scan that produced the finding is recorded instead.
     */
    public function up(): void
    {
        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->foreignUuid('pull_request_review_id')->nullable()->change();
            $table->string('source', 20)->default('ai')->after('git_repository_id');
            $table->foreignUuid('secret_scan_id')->nullable()->after('pull_request_review_id')
                ->constrained('secret_scans')->cascadeOnDelete();

            $table->index(['pull_request_id', 'source']);
        });
    }

    /**
     * Reverse the schema change. Findings without a review cannot survive it.
     */
    public function down(): void
    {
        DB::table('pull_request_review_findings')->whereNull('pull_request_review_id')->delete();

        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->dropIndex(['pull_request_id', 'source']);
            $table->dropConstrainedForeignId('secret_scan_id');
            $table->dropColumn('source');
            $table->foreignUuid('pull_request_review_id')->nullable(false)->change();
        });
    }
};
