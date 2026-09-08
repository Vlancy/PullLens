<?php

namespace App\Providers;

use App\Models\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Sentinel\Drivers\Driver as SentinelDriver;
use Laravel\Sentinel\Sentinel;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Sentinel runs before the session middleware, so it can never read the authenticated
        // user. Register a named 'telescope' driver that always passes - the viewTelescope gate
        // (checked by Telescope's own Authorize middleware, which runs after session init)
        // is what enforces actual access control.
        Sentinel::extend('telescope', function ($app) {
            return new class(fn () => $app) extends SentinelDriver
            {
                /**
                 * Whether the current user may perform this request.
                 */
                public function authorize(Request $request): bool
                {
                    return true;
                }
            };
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->type === EntryType::JOB ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        // Telescope exposes queue payloads, request bodies and credentials in cleartext.
        // Authentication alone is not enough - require the observability role.
        Gate::define('viewTelescope', function (?User $user): bool {
            return $user?->hasRole(config('pulllens.observability.role')) === true;
        });
    }
}
