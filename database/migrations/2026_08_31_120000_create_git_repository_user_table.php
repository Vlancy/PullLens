<?php

use App\Enums\Users\RepositoryAccessLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the pivot granting individual users access to individual repositories.
     *
     * Only consulted for users who lack the `repositories.view-all` permission. Rows
     * cascade away with either side, so revoking access is a single delete and an
     * untracked repository leaves no dangling grants behind.
     */
    public function up(): void
    {
        Schema::create('git_repository_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('git_repository_id')->constrained('git_repositories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 20)->default(RepositoryAccessLevel::View->value);
            $table->timestamps();

            // One grant per user per repository; the level is updated in place.
            $table->unique(['git_repository_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_repository_user');
    }
};
