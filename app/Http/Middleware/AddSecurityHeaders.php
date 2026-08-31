<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies baseline hardening headers to every web response.
 *
 * These are cheap, framework-agnostic mitigations for clickjacking, MIME sniffing
 * and referrer leakage. A full Content-Security-Policy is intentionally not set
 * here: Vite injects inline module preloads, so a policy would have to be nonce
 * driven and belongs with the asset pipeline rather than in a blanket middleware.
 */
class AddSecurityHeaders
{
    /**
     * Header name => value. Existing headers are never overwritten, so a controller
     * can still opt out for a specific response (e.g. an embeddable page).
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // Only meaningful over TLS; sending it on plain HTTP is ignored by browsers
        // and would be wrong to advertise from a local http:// dev server.
        if ($request->secure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
