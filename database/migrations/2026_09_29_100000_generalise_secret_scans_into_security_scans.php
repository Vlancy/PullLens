<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn the gitleaks-only scan table into one shared by every security scanner.
     *
     * Existing rows are gitleaks runs, so they are backfilled as such and keep their
     * version under the scanner-neutral column name.
     */
    public function up(): void
    {
        Schema::rename('secret_scans', 'security_scans');

        Schema::table('security_scans', function (Blueprint $table) {
            $table->string('scanner', 20)->default('gitleaks')->after('git_repository_id');
            $table->string('scanner_version', 40)->nullable()->after('notes_commit_sha');
        });

        DB::table('security_scans')->update(['scanner_version' => DB::raw('gitleaks_version')]);

        Schema::table('security_scans', function (Blueprint $table) {
            // Index names survive a table rename, so the old name is the one to drop.
            $table->dropIndex('secret_scans_pull_request_id_head_sha_index');
            $table->dropColumn('gitleaks_version');
            $table->index(['pull_request_id', 'scanner', 'head_sha']);
        });
    }

    /**
     * Reverse the schema change. Rows from other scanners cannot survive it.
     */
    public function down(): void
    {
        DB::table('security_scans')->where('scanner', '!=', 'gitleaks')->delete();

        Schema::table('security_scans', function (Blueprint $table) {
            $table->string('gitleaks_version', 40)->nullable()->after('notes_commit_sha');
        });

        DB::table('security_scans')->update(['gitleaks_version' => DB::raw('scanner_version')]);

        Schema::table('security_scans', function (Blueprint $table) {
            $table->dropIndex(['pull_request_id', 'scanner', 'head_sha']);
            $table->dropColumn(['scanner', 'scanner_version']);
        });

        Schema::rename('security_scans', 'secret_scans');

        Schema::table('secret_scans', function (Blueprint $table) {
            $table->index(['pull_request_id', 'head_sha']);
        });
    }
};
