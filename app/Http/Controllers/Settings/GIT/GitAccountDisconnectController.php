<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitAccount;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use Illuminate\Http\RedirectResponse;

class GitAccountDisconnectController extends Controller
{
    /**
     * Disconnect a system-level Git account from this PullLens instance.
     */
    public function __invoke(GitAccount $gitAccount, GitAccountRepositoryInterface $gitAccounts): RedirectResponse
    {
        $gitAccounts->delete($gitAccount);

        return to_route('git-providers.edit')->with('status', 'Git account disconnected.');
    }
}
