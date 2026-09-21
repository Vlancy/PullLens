<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Release repositories the old settings form pinned to the default provider.
     *
     * The repository settings page used to send the default provider's id as the
     * form value for a repository that had none, so saving any unrelated setting
     * turned "follow the global default" into a hard pin. Those repositories stop
     * tracking the default even though nobody chose that.
     *
     * A repository that also carries its own ai_model made a deliberate choice, so
     * its pin is left alone - only the ones that never picked a model are released.
     */
    public function up(): void
    {
        $defaultProviderId = DB::table('ai_providers')
            ->where('is_default', true)
            ->value('id');

        if ($defaultProviderId === null) {
            return;
        }

        DB::table('git_repositories')
            ->where('ai_provider_id', $defaultProviderId)
            ->where(fn ($query) => $query->whereNull('ai_model')->orWhere('ai_model', ''))
            ->update(['ai_provider_id' => null]);
    }

    /**
     * Irreversible: the released rows are indistinguishable from repositories that
     * legitimately followed the global default all along, so there is nothing to
     * put back.
     */
    public function down(): void
    {
        //
    }
};
