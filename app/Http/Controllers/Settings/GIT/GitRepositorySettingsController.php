<?php

namespace App\Http\Controllers\Settings\GIT;

use App\Enums\GIT\MergeMethod;
use App\Enums\GIT\ReviewIntensity;
use App\Enums\GIT\ReviewTone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GIT\UpdateGitRepositorySettingsRequest;
use App\Models\GIT\GitRepository;
use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Support\ReviewLanguages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GitRepositorySettingsController extends Controller
{
    /**
     * Show the review settings for a single tracked repository.
     */
    public function edit(GitRepository $gitRepository, AiProviderRepositoryInterface $aiProviders): Response
    {
        $gitRepository->load('branches', 'aiProvider');

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
                'auto_fill_pr_description' => $gitRepository->auto_fill_pr_description,
                'auto_enhance_pr_title' => $gitRepository->auto_enhance_pr_title,
                'allow_comment_replies' => $gitRepository->allow_comment_replies,
                'auto_merge' => $gitRepository->auto_merge,
                'auto_merge_method' => $gitRepository->auto_merge_method,
                'review_language' => $gitRepository->review_language,
                'review_tone' => $gitRepository->review_tone,
                'use_emoji' => $gitRepository->use_emoji,
                'base_branches' => $gitRepository->base_branches ?? [],
                'tracked_branches' => $gitRepository->tracked_branches ?? [],
                'ai_provider_id' => $gitRepository->ai_provider_id ?? $aiProviders->default()?->id,
                'ai_model' => $gitRepository->ai_model,
                'review_intensity' => $gitRepository->review_intensity,
            ],
            'ai_providers' => $aiProviders->enabled()->map(fn ($provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'provider_driver' => $provider->provider_driver,
                'default_model' => $provider->default_model,
                'is_default' => $provider->is_default,
            ])->values(),
            'branches' => $branchNames,
            'languages' => ReviewLanguages::all(),
            'merge_methods' => MergeMethod::values(),
            'review_intensities' => ReviewIntensity::values(),
            'review_tones' => ReviewTone::values(),
            'update_url' => route('integrations.repositories.settings.update', $gitRepository->id),
            'sync_branches_url' => route('integrations.repositories.branches.sync', $gitRepository->id),
            'back_url' => route('integrations.edit'),
            'status' => session('status'),
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
