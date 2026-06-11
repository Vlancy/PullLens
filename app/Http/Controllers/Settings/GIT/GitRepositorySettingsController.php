<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GIT\UpdateGitRepositorySettingsRequest;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GitRepositorySettingsController extends Controller
{
    private const LANGUAGES = [
        'English',
        'Spanish',
        'French',
        'German',
        'Portuguese',
        'Italian',
        'Dutch',
        'Arabic',
        'Chinese',
        'Japanese',
        'Korean',
    ];

    private const MERGE_METHODS = ['merge', 'squash', 'rebase'];

    /**
     * Show the review settings for a single tracked repository.
     */
    public function edit(GitRepository $gitRepository): Response
    {
        $gitRepository->load('branches');

        $branchNames = $gitRepository->branches
            ->sortBy(fn ($branch) => [
                $branch->name === $gitRepository->default_branch ? 0 : 1,
                $branch->name,
            ])
            ->pluck('name')
            ->values()
            ->all();

        return Inertia::render('settings/git-repository-settings', [
            'repository' => [
                'id' => $gitRepository->id,
                'name' => $gitRepository->name,
                'full_name' => $gitRepository->full_name,
                'owner_login' => $gitRepository->owner_login,
                'owner_type' => $gitRepository->owner_type,
                'default_branch' => $gitRepository->default_branch,
                'is_private' => $gitRepository->is_private,
                'web_url' => $gitRepository->web_url,
                'reviews_enabled' => $gitRepository->reviews_enabled,
                'auto_review_on_open' => $gitRepository->auto_review_on_open,
                'auto_approve' => $gitRepository->auto_approve,
                'auto_apply_labels' => $gitRepository->auto_apply_labels,
                'allow_comment_replies' => $gitRepository->allow_comment_replies,
                'auto_merge' => $gitRepository->auto_merge,
                'auto_merge_method' => $gitRepository->auto_merge_method,
                'review_language' => $gitRepository->review_language,
                'base_branches' => $gitRepository->base_branches ?? [],
                'tracked_branches' => $gitRepository->tracked_branches ?? [],
            ],
            'branches' => $branchNames,
            'languages' => self::LANGUAGES,
            'merge_methods' => self::MERGE_METHODS,
            'update_url' => route('integrations.repositories.settings.update', $gitRepository->id),
            'back_url' => route('integrations.edit'),
        ]);
    }

    /**
     * Persist the review settings for a single tracked repository.
     */
    public function update(
        UpdateGitRepositorySettingsRequest $request,
        GitRepository $gitRepository,
        GitRepositoryRepositoryInterface $repositories,
    ): RedirectResponse {
        $repositories->update($gitRepository, $request->validated());

        return to_route('integrations.repositories.settings.edit', $gitRepository->id)
            ->with('status', 'Repository settings saved.');
    }
}
