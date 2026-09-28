<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that records each gitleaks run against a pull request head.
     */
    public function up(): void
    {
        Schema::create('secret_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('head_sha', 40);
            $table->string('status', 20);
            $table->unsignedInteger('findings_count')->default(0);
            $table->unsignedInteger('files_scanned')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);
            $table->unsignedBigInteger('check_run_id')->nullable();
            $table->string('notes_commit_sha', 40)->nullable();
            $table->string('gitleaks_version', 40)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['pull_request_id', 'head_sha']);
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('secret_scans');
    }
};
