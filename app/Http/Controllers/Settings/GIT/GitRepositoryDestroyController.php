<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Services\Tasks\Features\FeatureManager;
use Illuminate\Http\RedirectResponse;

class GitRepositoryDestroyController extends Controller
{
    /**
     * Stop tracking a repository and remove its stored branches.
     *
     * Project features that only grouped this repository's tasks are pruned afterwards.
     */
    public function __invoke(
        GitRepository $gitRepository,
        GitRepositoryRepositoryInterface $repositories,
        FeatureManager $features,
    ): RedirectResponse {
        $repositories->delete($gitRepository);
        $features->pruneEmpty();

        return to_route('integrations.edit')
            ->with('status', 'Repository removed from tracking.');
    }
}
