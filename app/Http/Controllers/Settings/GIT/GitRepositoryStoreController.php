<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\GitProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GIT\StoreGitRepositoriesRequest;
use App\Models\GIT\GitAccount;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use App\Services\Git\RepositorySelectionSynchronizer;
use Illuminate\Http\RedirectResponse;

class GitRepositoryStoreController extends Controller
{
    /**
     * Persist the operator's repository selection (with branches) for an account.
     */
    public function __invoke(
        StoreGitRepositoriesRequest $request,
        string $provider,
        RepositorySelectionSynchronizer $synchronizer,
        GitAccountRepositoryInterface $gitAccounts,
    ): RedirectResponse {
        $gitProvider = GitProvider::tryFrom($provider) ?? abort(404);

        $account = $gitAccounts->find($request->integer('account_id'));

        abort_unless(
            $account instanceof GitAccount && $account->provider === $gitProvider,
            404,
        );

        $synchronizer->sync($account, $request->validated('repositories'));

        return to_route('integrations.edit')
            ->with('status', 'Tracked repositories updated.');
    }
}
