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
    public function overview(): Response
    {
        return Inertia::render('reports/overview', [
            'stats' => $this->reports->overview(),
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

        return Inertia::render('reports/daily', [
            'days' => $this->reports->daily($period),
            'period' => $period,
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

    /** Render the unresolved findings list for a single repository. */
    public function repositoryFindings(GitRepository $gitRepository): Response
    {
        $findings = PullRequestReviewFinding::with(['pullRequest'])
            ->where('git_repository_id', $gitRepository->id)
            ->whereNull('resolved_at')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (PullRequestReviewFinding $f) => [
                'id' => $f->id,
                'title' => $f->title,
                'severity' => $f->severity?->value,
                'category' => $f->category?->value,
                'file' => $f->file,
                'line' => $f->line,
                'explanation' => $f->explanation,
                'suggested_fix' => $f->suggested_fix,
                'pull_request' => $f->pullRequest ? [
                    'number' => $f->pullRequest->number,
                    'title' => $f->pullRequest->title,
                    'web_url' => $f->pullRequest->web_url,
                    'state' => $f->pullRequest->state?->value,
                ] : null,
            ]);

        $resolutionTypes = array_map(
            fn (FindingResolutionType $t) => ['value' => $t->value, 'label' => $t->label()],
            FindingResolutionType::cases(),
        );

        return Inertia::render('reports/repository-findings', [
            'repository' => [
                'id' => $gitRepository->id,
                'full_name' => $gitRepository->full_name,
                'web_url' => $gitRepository->web_url,
            ],
            'findings' => $findings,
            'resolution_types' => $resolutionTypes,
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
