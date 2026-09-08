<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Correct the attribution of the tasks recorded before it followed the commits.
     *
     * Historical rows carry the pull request's author, so a lead who opened a pull
     * request on someone else's behalf collected their work. Re-running the resolver
     * over the existing rows keeps old reports consistent with new ones. It is
     * idempotent: a row already pointing at the right developer is left untouched.
     */
    public function up(): void
    {
        Artisan::call('tasks:reattribute-authors');
    }

    /**
     * Attribution cannot be reversed: the previous value was the pull request's
     * author, which is still available on the pull request itself.
     */
    public function down(): void
    {
        //
    }
};
