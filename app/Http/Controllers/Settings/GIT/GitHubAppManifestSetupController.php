<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Services\Git\GitHubAppManifest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GitHubAppManifestSetupController extends Controller
{
    /**
     * Start GitHub App Manifest setup and persist a CSRF-style state token.
     */
    public function __invoke(Request $request, GitHubAppManifest $manifest): View
    {
        $state = Str::random(40);

        $request->session()->put('github_app_manifest_state', $state);

        return view('git.github-app-manifest', [
            'action' => 'https://github.com/settings/apps/new',
            'manifest' => json_encode($manifest->make($state), JSON_THROW_ON_ERROR),
        ]);
    }
}
