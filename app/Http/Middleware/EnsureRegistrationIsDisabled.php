<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard block on self-service account creation.
 *
 * PullLens provisions accounts through the admin panel only. Fortify's registration
 * feature is already commented out in config/fortify.php, so these routes normally do
 * not exist — this middleware is the second line of defence, guaranteeing a 404 even
 * if a package upgrade, a stray route file or a mis-merge re-registers one.
 */
class EnsureRegistrationIsDisabled
{
    /**
     * Request paths that must never resolve while registration is disabled.
     *
     * @var array<int, string>
     */
    private const BLOCKED_PATHS = [
        'register',
        'register/*',
        'api/register',
        'api/*/register',
        'auth/register',
        'user/register',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('pulllens.registration_enabled') === true) {
            return $next($request);
        }

        if ($request->is(...self::BLOCKED_PATHS)) {
            // 404 rather than 403: do not confirm that a registration endpoint exists.
            abort(Response::HTTP_NOT_FOUND);
        }

        return $next($request);
    }
}
