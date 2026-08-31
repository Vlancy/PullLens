<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Services\Git\GitHubApiClient;
use Illuminate\Http\RedirectResponse;

class GitRepositoryBranchSyncController extends Controller
{
    /**
     * Fetch the latest branches from the provider and replace the stored set.
     */
    public function __invoke(
        GitRepository $gitRepository,
        GitHubApiClient $api,
        GitRepositoryRepositoryInterface $repositories,
    ): RedirectResponse {
        $account = $gitRepository->account;
        [$owner, $name] = explode('/', $gitRepository->full_name, 2);

        $branches = collect($api->branches($account, $owner, $name))
            ->map(fn (array $branch): array => [
                'name' => (string) data_get($branch, 'name'),
                'commit_sha' => data_get($branch, 'commit.sha'),
                'is_protected' => (bool) data_get($branch, 'protected', false),
                'is_default' => data_get($branch, 'name') === $gitRepository->default_branch,
            ])
            ->values()
            ->all();

        $repositories->replaceBranches($gitRepository, $branches);

        return to_route('integrations.repositories.settings.edit', $gitRepository->id)
            ->with('status', 'Branches synced.');
    }
}
