<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Services\Git\GitHubAppManifestConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GitHubAppManifestCallbackController extends Controller
{
    /**
     * Validate the GitHub App Manifest callback and store provider app credentials.
     */
    public function __invoke(Request $request, GitHubAppManifestConverter $converter, string $state): RedirectResponse
    {
        abort_unless(
            $request->filled('code')
            && hash_equals((string) $request->session()->pull('github_app_manifest_state'), $state),
            403,
        );

        $converter->convert((string) $request->query('code'));

        return to_route('git-providers.edit')
            ->with('status', 'GitHub App configured. Next, click Connect GitHub to attach a system account.');
    }
}
