<?php

namespace App\Http\Controllers\Repositories;

use App\Enums\GIT\PullRequestState;
use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequest;
use App\Models\GIT\PullRequestReviewFinding;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryIndexController extends Controller
{
    public function __invoke(): Response
    {
        $openPrCounts = PullRequest::selectRaw('git_repository_id, count(*) as count')
            ->where('state', PullRequestState::Open)
            ->groupBy('git_repository_id')
            ->pluck('count', 'git_repository_id');

        $totalPrCounts = PullRequest::selectRaw('git_repository_id, count(*) as count')
            ->groupBy('git_repository_id')
            ->pluck('count', 'git_repository_id');

        $findingsCounts = PullRequestReviewFinding::selectRaw('git_repository_id, count(*) as count')
            ->groupBy('git_repository_id')
            ->pluck('count', 'git_repository_id');

        $repositories = GitRepository::orderBy('full_name')
            ->get()
            ->map(fn ($repo) => [
                'id'              => $repo->id,
                'full_name'       => $repo->full_name,
                'name'            => $repo->name,
                'owner_login'     => $repo->owner_login,
                'provider'        => $repo->provider?->value,
                'is_private'      => $repo->is_private,
                'web_url'         => $repo->web_url,
                'default_branch'  => $repo->default_branch,
                'reviews_enabled' => $repo->reviews_enabled,
                'open_prs_count'  => (int) ($openPrCounts->get($repo->id, 0)),
                'total_prs_count' => (int) ($totalPrCounts->get($repo->id, 0)),
                'findings_count'  => (int) ($findingsCounts->get($repo->id, 0)),
            ]);

        return Inertia::render('repositories/index', [
            'repositories' => $repositories,
        ]);
    }
}
