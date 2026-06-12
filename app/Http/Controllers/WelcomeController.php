<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class WelcomeController extends Controller
{
    /**
     * Render the welcome page with required browser security headers.
     */
    public function __invoke(): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        return Inertia::render('welcome')
            ->toResponse(request())
            ->withHeaders([
                'Permissions-Policy' => 'publickey-credentials-get=(), publickey-credentials-create=()',
            ]);
    }
}
