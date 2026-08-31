<?php

namespace App\Http\Controllers\Welcome;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class WelcomeController extends Controller
{
    /**
     * Render the welcome page with required browser security headers.
     */
    public function __invoke(): JsonResponse|Response
    {
        return Inertia::render('welcome')
            ->toResponse(request())
            ->withHeaders([
                'Permissions-Policy' => 'publickey-credentials-get=(), publickey-credentials-create=()',
            ]);
    }
}
