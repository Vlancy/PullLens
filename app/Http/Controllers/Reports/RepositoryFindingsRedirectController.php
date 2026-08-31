<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use Illuminate\Http\RedirectResponse;

/**
 * Sends a repository's "view findings" link to the global findings page, pre-filtered.
 *
 * A controller rather than a closure so the route file can be cached and the
 * behaviour can be tested directly.
 */
class RepositoryFindingsRedirectController extends Controller
{
    public function __invoke(GitRepository $gitRepository): RedirectResponse
    {
        return redirect()->route('findings.index', [
            'repository_id' => $gitRepository->id,
            'status' => 'all',
        ]);
    }
}
