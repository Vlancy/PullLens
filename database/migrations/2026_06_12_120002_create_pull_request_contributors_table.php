<?php

use App\Enums\GIT\ContributorRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table that tracks every person who participated in a pull request.
     * A contributor may appear multiple times with different roles (author + reviewer).
     * Used for per-user performance reports, commit attribution, and review participation.
     */
    public function up(): void
    {
        Schema::create('pull_request_contributors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pull_request_id')->constrained('pull_requests')->cascadeOnDelete();
            $table->string('login');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('provider_user_id')->nullable();
            $table->string('role', 20)->default(ContributorRole::Author->value);
            $table->unsignedInteger('commit_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->timestamps();

            // A login can only hold one role per PR (author once, reviewer once, etc.)
            $table->unique(['pull_request_id', 'login', 'role']);
            $table->index(['pull_request_id', 'login']);
            // Supports cross-PR user performance queries on a given repository
            $table->index('login');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pull_request_contributors');
    }
};
