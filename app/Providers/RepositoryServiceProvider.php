<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register repository contracts from the active repository driver configuration.
     */
    public function register(): void
    {
        $config = config('repository');

        if (! is_array($config) || empty($config['driver'])) {
            return;
        }

        $bindings = ($config['drivers'] ?? [])[$config['driver']] ?? [];

        foreach ($bindings as $interface => $implementation) {
            $this->app->singleton($interface, $implementation);
        }
    }
}
