<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point findings at the shared scan table and give scanner findings structured metadata.
     *
     * Existing gitleaks findings are backfilled as secrets, with the rule taken from
     * their dedupe key (gitleaks:{rule}:{file}:{hash}).
     */
    public function up(): void
    {
        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->renameColumn('secret_scan_id', 'security_scan_id');
        });

        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('suggested_fix');
        });

        DB::table('pull_request_review_findings')
            ->where('source', 'gitleaks')
            ->select(['id', 'dedupe_key'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $rule = explode(':', (string) $row->dedupe_key)[1] ?? '';
                    $metadata = $rule === '' ? ['kind' => 'secret'] : ['kind' => 'secret', 'rule_id' => $rule];

                    DB::table('pull_request_review_findings')
                        ->where('id', $row->id)
                        ->update(['metadata' => json_encode($metadata)]);
                }
            });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });

        Schema::table('pull_request_review_findings', function (Blueprint $table) {
            $table->renameColumn('security_scan_id', 'secret_scan_id');
        });
    }
};
