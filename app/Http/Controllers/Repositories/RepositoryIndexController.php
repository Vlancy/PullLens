<?php

namespace App\Http\Controllers\Repositories;

use App\Enums\GIT\PullRequestState;
use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Support\Presenters\GIT\RepositoryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lists every tracked repository with its activity counts.
 *
 * Counts are loaded with withCount() subqueries rather than joins, so a repository
 * with many findings still produces exactly one row and the totals stay accurate.
 */
class RepositoryIndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $repositories = GitRepository::query()
            // Users without `repositories.view-all` see only what they were granted.
            ->visibleTo($request->user())
            ->withCount([
                'pullRequests as total_prs_count',
                'pullRequests as open_prs_count' => fn (Builder $q) => $q->where('state', PullRequestState::Open->value),
                'findings as findings_count',
            ])
            ->orderBy('full_name')
            ->get()
            ->map(static fn (GitRepository $repository): array => [
                ...RepositoryPresenter::toArray($repository),
                'open_prs_count' => (int) $repository->open_prs_count,
                'total_prs_count' => (int) $repository->total_prs_count,
                'findings_count' => (int) $repository->findings_count,
            ])
            ->all();

        return Inertia::render('repositories/index', [
            'repositories' => $repositories,
        ]);
    }
}
