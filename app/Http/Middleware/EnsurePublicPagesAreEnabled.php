<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides the pages that exist for visitors when the instance has none.
 *
 * With `HOMEPAGE_LOGIN` on, the deployment is internal: the landing page is
 * replaced by the login screen and the hosted user guide should not answer
 * either, or it would still describe the instance to anyone who found the URL.
 *
 * The answer is 404 rather than 403 on purpose. A 403 confirms the route exists
 * and is merely switched off, which is exactly the fact being withheld.
 */
class EnsurePublicPagesAreEnabled
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if((bool) config('pulllens.homepage_login'), 404);

        return $next($request);
    }
}
