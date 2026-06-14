<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Models\GIT\GitProviderApp;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use App\Services\Git\AvailableRepositoryBrowser;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitRepositoryBrowseController extends Controller
{
    /**
     * Return all installations and repositories the GitHub App can access.
     * Uses App-level JWT auth so every installation is visible regardless of which user accounts are connected.
     */
    public function __invoke(
        Request $request,
        string $provider,
        AvailableRepositoryBrowser $browser,
        GitProviderAppRepositoryInterface $providerApps,
    ): JsonResponse {
        $gitProvider = GitProvider::tryFrom($provider) ?? abort(404);

        $app = $providerApps->findByProvider($gitProvider);

        abort_unless($app instanceof GitProviderApp, 404);

        try {
            return response()->json([
                'installations' => $browser->browseAsApp($app),
            ]);
        } catch (RequestException $exception) {
            return response()->json([
                'message' => 'Could not load repositories from GitHub. Check the App credentials and try again.',
            ], 502);
        }
    }
}
