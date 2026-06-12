<?php

namespace App\Providers;

use App\Models\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Laravel\Sentinel\Drivers\Driver as SentinelDriver;
use Laravel\Sentinel\Sentinel;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');

        // Sentinel runs before the session middleware, so it can never read the authenticated
        // user. Register a named 'horizon' driver that always passes — the viewHorizon gate
        // (checked by Horizon's own Authenticate middleware, which runs after session init)
        // is what enforces actual access control.
        Sentinel::extend('horizon', function ($app) {
            return new class(fn () => $app) extends SentinelDriver {
                public function authorize(Request $request): bool
                {
                    return true;
                }
            };
        });
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user): bool {
            return $user !== null;
        });
    }
}
