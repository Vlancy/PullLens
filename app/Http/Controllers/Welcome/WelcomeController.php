<?php

namespace App\Http\Controllers\Welcome;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class WelcomeController extends Controller
{
    /**
     * Render the welcome page with required browser security headers.
     *
     * On an internal deployment (`HOMEPAGE_LOGIN=true`) there is nobody to market
     * to, so `/` becomes the login screen instead. Fortify's own guest middleware
     * forwards an already signed in visitor on to the dashboard from there.
     */
    public function __invoke(): JsonResponse|RedirectResponse|Response
    {
        if (config('pulllens.homepage_login')) {
            return redirect()->route('login');
        }

        return Inertia::render('welcome', [
            // Hides the way in without closing it: /login still answers for anyone
            // who was given the address.
            'hideLogin' => (bool) config('pulllens.hide_login'),
        ])
            ->toResponse(request())
            ->withHeaders([
                'Permissions-Policy' => 'publickey-credentials-get=(), publickey-credentials-create=()',
            ]);
    }
}
