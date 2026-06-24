<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->boolean('record_all_activity')->default(false)->after('reviews_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('git_repositories', function (Blueprint $table) {
            $table->dropColumn('record_all_activity');
        });
    }
};
