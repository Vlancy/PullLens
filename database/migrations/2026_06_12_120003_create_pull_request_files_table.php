<?php

use App\Enums\GIT\PullRequestFileStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that stores file-level changes within a pull request.
     * Detected language enables language/stack distribution reports.
     * The patch column holds the raw unified diff for AI review context.
     */
    public function up(): void
    {
        Schema::create('pull_request_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->string('filename', 1024);
            $table->string('previous_filename', 1024)->nullable();
            $table->string('status', 20)->default(PullRequestFileStatus::Modified->value);
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->string('language')->nullable();
            $table->longText('patch')->nullable();
            $table->timestamps();

            $table->unique(['pull_request_id', 'filename']);
            $table->index(['pull_request_id', 'language']);
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_request_files');
    }
};
