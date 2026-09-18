<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDates();
        $this->configureModels();
        $this->configureSecurity();
        $this->configureRateLimiting();
    }

    /**
     * Use immutable dates everywhere so a passed-around Carbon instance can never be
     * mutated by a callee (a common source of off-by-one reporting bugs).
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    /**
     * Fail loudly when code assigns an attribute the model does not declare, or reads
     * one that was never selected - both are typos that otherwise fail silently.
     *
     * Lazy-load and missing-attribute prevention are deliberately left off: the
     * reporting pages legitimately resolve relations on demand and select partial
     * column sets, so enabling them would trade real bugs for noise.
     */
    private function configureModels(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventLazyLoading(false);
        Model::preventAccessingMissingAttributes(false);
    }

    /**
     * Production-only hardening.
     */
    private function configureSecurity(): void
    {
        // `migrate:fresh`, `db:wipe` and friends are refused against a production database.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Behind a TLS-terminating proxy the app sees plain HTTP; force generated URLs
        // (password resets, OAuth callbacks) to https so they are not downgraded.
        if ($this->app->isProduction()) {
            $servedOverHttps = str_starts_with(strtolower((string) config('app.url')), 'https://');

            if ($servedOverHttps) {
                URL::forceScheme('https');

                // Mark the session cookie Secure so the browser never sends it over a
                // plain-HTTP request. Forced rather than left to configuration: an
                // operator who forgets the variable would otherwise ship a session
                // cookie that leaks on the first accidental http:// link.
                config(['session.secure' => true]);
            }

            // On a declared plain-HTTP deployment SESSION_SECURE_COOKIE is left alone.
            // A browser discards a Secure cookie that arrived over http://, which costs
            // the session and the CSRF token, and every POST - login first - answers 419.
            // Serving the instance over HTTPS is the fix; refusing to start it is not.
            config(['session.same_site' => config('session.same_site', 'lax')]);
        }

        Password::defaults(fn (): ?Password => $this->app->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Named rate limiters referenced from the route files.
     */
    private function configureRateLimiting(): void
    {
        // Queued AI reviews - bounds provider spend per minute.
        RateLimiter::for('ai-reviews', fn () => Limit::perMinute(5));

        // Assistant chat: each request is a paid streaming completion, so it is limited
        // per authenticated user rather than per IP (shared office NAT would collide).
        RateLimiter::for('assistant', fn (Request $request) => Limit::perMinute(20)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Inbound provider webhooks are unauthenticated at the HTTP layer. Keyed by IP
        // and generous enough for GitHub's burst on a large push.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by((string) $request->ip()));
    }
}
