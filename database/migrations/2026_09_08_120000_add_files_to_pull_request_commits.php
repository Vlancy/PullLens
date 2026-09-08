<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which paths each commit touched.
     *
     * A pull request is often opened by someone who wrote none of it, so a task must
     * be attributed through the commit that carried its files rather than through the
     * pull request's author. The per-commit detail is already fetched for the addition
     * and deletion counts, so keeping the paths costs no extra API call.
     */
    public function up(): void
    {
        Schema::table('pull_request_commits', function (Blueprint $table) {
            $table->json('files')->nullable()->after('changed_files_count');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::table('pull_request_commits', function (Blueprint $table) {
            $table->dropColumn('files');
        });
    }
};
