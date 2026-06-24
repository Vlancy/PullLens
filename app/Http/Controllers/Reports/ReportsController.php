<?php

namespace App\Http\Controllers\Reports;

use App\Enums\GIT\FindingResolutionType;
use App\Http\Controllers\Controller;
use App\Jobs\GIT\SyncPullRequestDetails;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestReviewFinding;
use App\Services\Reports\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportsController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /** Render the system-wide overview report page. */
    public function overview(Request $request): Response
    {
        $period = $request->get('period', 'today');
        $author = $request->get('author');

        $authors = DB::table('pull_requests')
            ->select(['author_login', 'author_name'])
            ->whereNotNull('author_login')
            ->groupBy(['author_login', 'author_name'])
            ->orderBy('author_name')
            ->get();

        return Inertia::render('reports/overview', [
            'stats'       => $this->reports->overview($period, $author),
            'period'      => $period,
            'author'      => $author,
            'authors'     => $authors,
            'leaderboard' => $this->reports->leaderboard($period),
        ]);
    }

    /** Render the per-developer metrics report page. */
    public function developers(Request $request): Response
    {
        $period = $request->get('period', 'all');
        $repoId = $request->get('repo_id');

        $repos = DB::table('git_repositories')
            ->where('reviews_enabled', true)
            ->select(['id', 'name', 'full_name'])
            ->orderBy('full_name')
            ->get();

        return Inertia::render('reports/developers', [
            'developers' => $this->reports->developers($period, $repoId),
            'period' => $period,
            'repo_id' => $repoId,
            'repositories' => $repos,
            'sync_commit_stats_url' => route('reports.sync-commit-stats'),
        ]);
    }

    /** Render the per-repository statistics report page. */
    public function repositories(): Response
    {
        return Inertia::render('reports/repositories', [
            'repositories' => $this->reports->repositories(),
        ]);
    }

    /** Render the commit quality report page. */
    public function commits(Request $request): Response
    {
        $period = $request->get('period', 'all');

        return Inertia::render('reports/commits', [
            'commits' => $this->reports->commits($period),
            'period' => $period,
        ]);
    }

    /** Render the daily performance breakdown report page. */
    public function daily(Request $request): Response
    {
        $period = $request->get('period', '30d');
        $author = $request->get('author');

        $authors = DB::table('pull_requests')
            ->select(['author_login', 'author_name'])
            ->whereNotNull('author_login')
            ->groupBy(['author_login', 'author_name'])
            ->orderBy('author_name')
            ->get();

        return Inertia::render('reports/daily', [
            'days'    => $this->reports->daily($period, $author),
            'period'  => $period,
            'author'  => $author,
            'authors' => $authors,
        ]);
    }

    /** Render the per-developer daily effort breakdown page. */
    public function developerDaily(Request $request): Response
    {
        $period = $request->get('period', '7d');
        $repoId = $request->get('repo_id');

        $repos = DB::table('git_repositories')
            ->where('reviews_enabled', true)
            ->select(['id', 'name', 'full_name'])
            ->orderBy('full_name')
            ->get();

        return Inertia::render('reports/developer-daily', [
            'rows' => $this->reports->developerDaily($period, $repoId),
            'period' => $period,
            'repo_id' => $repoId,
            'repositories' => $repos,
        ]);
    }

    /** Queue SyncPullRequestDetails for every PR that has commits with no line stats. */
    public function syncCommitStats(): RedirectResponse
    {
        $ids = DB::table('pull_request_commits')
            ->where('additions', 0)
            ->where('deletions', 0)
            ->distinct()
            ->pluck('pull_request_id');

        $ids->each(fn ($id) => SyncPullRequestDetails::dispatch($id));

        return back()->with('status', "Queued {$ids->count()} PR(s) for commit stats sync.");
    }
}
