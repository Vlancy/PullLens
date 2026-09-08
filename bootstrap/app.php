<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureRegistrationIsDisabled;
use App\Http\Middleware\GuardResponseHeaderSize;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Appearance and sidebar cookies are read by JS before hydration and hold no
        // security-relevant data; everything else stays encrypted.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Provider webhooks are authenticated by HMAC signature, not by a session token.
        $middleware->preventRequestForgery(except: ['webhooks/*']);

        // Runs before routing resolves, so it also covers routes registered by packages.
        $middleware->prepend(EnsureRegistrationIsDisabled::class);

        // Outermost, so it sees the response exactly as the web server will: cookies
        // queued, encrypted and already attached.
        $middleware->prepend(GuardResponseHeaderSize::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AddSecurityHeaders::class,
        ]);

        // Authorization middleware from spatie/laravel-permission, used as
        // `role:admin` / `can:findings.resolve` guards in the route files.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
