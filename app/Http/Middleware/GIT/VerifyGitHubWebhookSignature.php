<?php

namespace App\Http\Middleware\GIT;

use App\Enums\GIT\GitProvider;
use App\Models\GIT\GitProviderApp;
use App\Services\Git\GitHubWebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inbound GitHub webhooks by their HMAC-SHA256 signature.
 *
 * The webhook route is unauthenticated and CSRF-exempt, so this signature is the
 * *only* thing standing between an anonymous caller and the ability to queue AI
 * review jobs. It therefore fails closed: a missing GitHub App, a missing webhook
 * secret or an absent signature all reject the request. Verification can only be
 * relaxed outside production, and only by explicit configuration.
 *
 * Extracting it from the controller keeps transport-level authentication out of
 * the business handlers and makes it reusable for future providers.
 */
class VerifyGitHubWebhookSignature
{
    /**
     * Inject the git hub webhook verifier this class delegates to.
     */
    public function __construct(private readonly GitHubWebhookVerifier $verifier) {}

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = GitProviderApp::query()
            ->where('provider', GitProvider::Github->value)
            ->value('webhook_secret');

        if (blank($secret)) {
            // No secret means we cannot authenticate anyone. Refuse unless an operator
            // has deliberately opted out for local debugging.
            if ($this->signatureRequired()) {
                $this->reject($request, 'no webhook secret configured');
            }

            return $next($request);
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! $this->verifier->verify($request->getContent(), $signature, (string) $secret)) {
            $this->reject($request, 'signature mismatch');
        }

        return $next($request);
    }

    /**
     * Whether a valid signature is mandatory for this request.
     *
     * Always true in production, regardless of configuration - the switch exists for
     * local tunnelling setups, not as a production escape hatch.
     */
    private function signatureRequired(): bool
    {
        return app()->isProduction()
            || (bool) config('pulllens.webhooks.require_signature', true);
    }

    /**
     * Log the rejection without echoing any attacker-controlled content, then abort.
     */
    private function reject(Request $request, string $reason): never
    {
        Log::warning('webhook.rejected', [
            'provider' => GitProvider::Github->value,
            'event' => (string) $request->header('X-GitHub-Event', ''),
            'reason' => $reason,
            'ip' => $request->ip(),
            'body_bytes' => strlen($request->getContent()),
        ]);

        abort(Response::HTTP_FORBIDDEN, 'Invalid webhook signature.');
    }
}
