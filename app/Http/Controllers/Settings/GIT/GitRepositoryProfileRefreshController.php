<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Jobs\GIT\RefreshRepositoryProfile;
use App\Models\GIT\GitRepository;
use Illuminate\Http\RedirectResponse;

class GitRepositoryProfileRefreshController extends Controller
{
    /**
     * Queue an AI refresh of the repository's profile tags.
     */
    public function __invoke(GitRepository $gitRepository): RedirectResponse
    {
        RefreshRepositoryProfile::dispatch($gitRepository->id);

        return to_route('integrations.repositories.settings.edit', $gitRepository->id)
            ->with('status', 'Profile refresh queued.');
    }
}
