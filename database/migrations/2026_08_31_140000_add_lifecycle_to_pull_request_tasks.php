<?php

use App\Enums\GIT\TaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give tasks a lifecycle, rework counters and an external tracker reference.
     *
     * The counters are denormalised on purpose. "How much of this developer's work
     * came back" is the headline question of the tasks dashboard, and deriving it per
     * row from the link table would turn one indexed scan into a correlated subquery
     * per task. They are recomputed from the links whenever a link changes, so the
     * link table stays the source of truth.
     */
    public function up(): void
    {
        Schema::table('pull_request_tasks', function (Blueprint $table) {
            $table->string('status', 20)->default(TaskStatus::InProgress->value)->after('type');

            // How many later tasks came back to this one, split by what they did.
            $table->unsignedInteger('rework_count')->default(0)->after('estimated_hours');
            $table->unsignedInteger('revision_count')->default(0)->after('rework_count');

            // First delivery is kept separately from delivered_at so that a revision
            // cannot quietly rewrite when the work originally shipped.
            $table->timestamp('first_delivered_at')->nullable()->after('delivered_at');
            $table->timestamp('reverted_at')->nullable()->after('first_delivered_at');

            // External tracker reference, detected from the branch, title or body.
            // Populated today, integrated with later.
            $table->string('external_provider', 30)->nullable()->after('reverted_at');
            $table->string('external_key', 100)->nullable()->after('external_provider');
            $table->string('external_url', 2048)->nullable()->after('external_key');

            $table->index('status');
            $table->index(['external_provider', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::table('pull_request_tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['external_provider', 'external_key']);

            $table->dropColumn([
                'status',
                'rework_count',
                'revision_count',
                'first_delivered_at',
                'reverted_at',
                'external_provider',
                'external_key',
                'external_url',
            ]);
        });
    }
};
