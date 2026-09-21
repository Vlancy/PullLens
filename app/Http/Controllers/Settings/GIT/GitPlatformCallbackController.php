<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Services\Git\GitAccountConnector;
use App\Services\Git\GitProviderAppConfigurator;
use App\Services\Git\SocialiteGitUserMapper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Completes the OAuth handshake with a git platform.
 *
 * This is the one route in the settings area a browser reaches while carrying
 * state PullLens did not set, so it is also the one that fails for reasons the
 * instance does not control: the operator refusing the authorization, the
 * handshake sitting long enough for the session to be replaced, the provider
 * being unreachable. Every one of those used to arrive as a bare 500, which
 * tells the operator nothing and reads like the instance itself is broken.
 */
class GitPlatformCallbackController extends Controller
{
    /**
     * Create the callback controller with provider connection dependencies.
     */
    public function __construct(
        private readonly GitAccountConnector $connector,
        private readonly GitProviderAppConfigurator $apps,
        private readonly SocialiteGitUserMapper $mapper,
    ) {}

    /**
     * Handle provider OAuth callback and connect the account at system level.
     */
    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $gitProvider = GitProvider::tryFrom($provider) ?? abort(404);

        // The provider reports a refusal by redirecting back with an error instead
        // of a code. Calling Socialite with that would throw on a missing code and
        // bury a perfectly ordinary "the operator said no" in a stack trace.
        if ($request->filled('error')) {
            return $this->failed($gitProvider, $this->describeProviderError($request));
        }

        $this->apps->configureSocialite($gitProvider);

        try {
            $socialiteUser = Socialite::driver($gitProvider->value)->user();
        } catch (InvalidStateException) {
            return $this->failed($gitProvider, __('The sign-in took too long or was started in another tab, so it could not be completed. Start it again from this page.'));
        } catch (Throwable $e) {
            // The message can carry the request URL, and that URL carries the
            // client secret on some providers, so it goes to the log and never to
            // the screen.
            Log::error('git_oauth.callback_failed', [
                'provider' => $gitProvider->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->failed($gitProvider, __('PullLens could not reach :provider to finish connecting. The details are in the instance log.', [
                'provider' => $gitProvider->label(),
            ]));
        }

        $this->connector->connect(
            $this->mapper->map($gitProvider, $socialiteUser),
        );

        return to_route('integrations.edit')
            ->with('status', "{$gitProvider->label()} account connected.");
    }

    /**
     * Send the operator back to the setup page with the reason it did not work.
     */
    private function failed(GitProvider $provider, string $reason): RedirectResponse
    {
        return to_route('integrations.edit')->with('connection_error', $reason);
    }

    /**
     * Turn the provider's own error parameters into a sentence.
     *
     * The description is the provider's wording, so it is trimmed to a sane length
     * before it is put on the page - it is external input, and an unbounded string
     * on an error banner is a layout break waiting to happen.
     */
    private function describeProviderError(Request $request): string
    {
        $description = trim((string) $request->query('error_description', ''));

        if ($description === '') {
            return __('The connection was not authorized, so no account was linked.');
        }

        return Str::limit($description, 200);
    }
}
