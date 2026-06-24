<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_commits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->string('sha', 40);
            $table->foreignUuid('pull_request_id')->nullable()->constrained('pull_requests')->nullOnDelete();
            $table->string('branch')->nullable();
            $table->string('author_login')->nullable()->index();
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->text('author_avatar_url')->nullable();
            $table->text('message')->nullable();
            $table->integer('additions')->default(0);
            $table->integer('deletions')->default(0);
            $table->integer('changed_files_count')->default(0);
            $table->timestamp('committed_at')->nullable()->index();
            $table->boolean('stats_synced')->default(false);
            $table->timestamps();

            $table->unique(['git_repository_id', 'sha']);
            $table->index(['git_repository_id', 'committed_at']);
            $table->index(['author_login', 'committed_at']);
        });

        // Backfill existing PR commits so developerDaily reports stay intact.
        // gen_random_uuid()::uuid is Postgres-only — skip on SQLite (test env).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("
                INSERT INTO repository_commits (
                    id, git_repository_id, sha, pull_request_id, branch,
                    author_login, author_name, author_email, author_avatar_url,
                    message, additions, deletions, changed_files_count,
                    committed_at, stats_synced, created_at, updated_at
                )
                SELECT
                    gen_random_uuid()::uuid,
                    pr.git_repository_id,
                    c.sha,
                    c.pull_request_id,
                    pr.target_branch,
                    c.author_login,
                    c.author_name,
                    c.author_email,
                    c.author_avatar_url,
                    c.message,
                    c.additions,
                    c.deletions,
                    c.changed_files_count,
                    c.committed_at,
                    true,
                    NOW(),
                    NOW()
                FROM pull_request_commits c
                JOIN pull_requests pr ON pr.id = c.pull_request_id
                ON CONFLICT (git_repository_id, sha) DO NOTHING
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_commits');
    }
};
