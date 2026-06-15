<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pull_request_reviews', function (Blueprint $table) {
            $table->unsignedInteger('prompt_tokens')->nullable()->after('review_duration_ms');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pull_request_reviews', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens']);
        });
    }
};
