<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use Illuminate\Http\RedirectResponse;

class GitRepositoryDestroyController extends Controller
{
    /**
     * Stop tracking a repository and remove its stored branches.
     */
    public function __invoke(
        GitRepository $gitRepository,
        GitRepositoryRepositoryInterface $repositories,
    ): RedirectResponse {
        $repositories->delete($gitRepository);

        return to_route('integrations.edit')
            ->with('status', 'Repository removed from tracking.');
    }
}
