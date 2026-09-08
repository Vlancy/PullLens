<?php

use App\Enums\GIT\TaskRelation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the directed graph of relationships between tasks.
     *
     * `task_id` is always the newer task and `related_task_id` the earlier one, so
     * "fixes" reads in one direction only. Storing it directed rather than as a
     * symmetric pair is what lets the UI say "fixed by" on one side and "fixes" on
     * the other from a single row.
     */
    public function up(): void
    {
        Schema::create('pull_request_task_links', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('task_id')->constrained('pull_request_tasks')->cascadeOnDelete();
            $table->foreignUuid('related_task_id')->constrained('pull_request_tasks')->cascadeOnDelete();

            $table->string('relation', 20)->default(TaskRelation::Relates->value);

            // Why the link was drawn, so an operator can judge an AI-proposed link.
            $table->text('reason')->nullable();
            $table->float('confidence')->nullable();

            // 'ai' or 'manual' - a person's correction must not be overwritten by the
            // next review, so the source is recorded rather than inferred.
            $table->string('source', 20)->default('ai');

            $table->timestamps();

            $table->unique(['task_id', 'related_task_id', 'relation']);
            $table->index('related_task_id');
            $table->index('relation');
        });
    }

    /**
     * Reverse the schema change.
     */
    public function down(): void
    {
        Schema::dropIfExists('pull_request_task_links');
    }
};
