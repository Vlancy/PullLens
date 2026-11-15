<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apply the schema change.
     */
    public function up(): void
    {
        Schema::table('pull_request_tasks', function (Blueprint $table) {
            $table->foreignUuid('task_feature_id')->nullable()->constrained('task_features')->nullOnDelete();
            $table->string('feature_source', 20)->nullable();
            $table->index('task_feature_id');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::table('pull_request_tasks', function (Blueprint $table) {
            $table->dropIndex(['task_feature_id']);
            $table->dropConstrainedForeignId('task_feature_id');
            $table->dropColumn('feature_source');
        });
    }
};
