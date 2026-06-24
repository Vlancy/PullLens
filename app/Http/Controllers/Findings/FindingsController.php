<?php

namespace App\Http\Controllers\Findings;

use App\Enums\GIT\FindingResolutionType;
use App\Http\Controllers\Controller;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FindingsController extends Controller
{
    public function index(Request $request): Response
    {
        $repoId      = $request->query('repository_id', '');
        $severity    = $request->query('severity', '');
        $category    = $request->query('category', '');
        $status      = $request->query('status', 'open');
        $search      = $request->query('search', '');
        $sortBy      = $request->query('sort_by', 'severity');
        $authorLogin = $request->query('author_login', '');
        $page        = max(1, (int) $request->query('page', 1));
        $perPage     = 25;

        $severities = $severity ? array_filter(explode(',', $severity)) : [];

        // ── Main findings query ───────────────────────────────────────────────
        $findingQuery = PullRequestReviewFinding::query()
            ->with([
                'pullRequest:id,number,title,web_url,state,git_repository_id',
                'repository:id,name,full_name',
            ])
            ->when($repoId, fn ($q) => $q->where('git_repository_id', $repoId))
            ->when($severities, fn ($q) => $q->whereIn('severity', $severities))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($search, fn ($q) => $q->where('title', 'ilike', "%{$search}%"))
            ->when($authorLogin, fn ($q) => $q->whereHas('pullRequest', fn ($pq) => $pq->where('author_login', $authorLogin)));

        match ($sortBy) {
            'date'     => $findingQuery->orderBy('created_at', 'desc'),
            'category' => $findingQuery->orderBy('category')
                ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END"),
            default    => $findingQuery
                ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
                ->orderBy('created_at', 'desc'),
        };

        $total    = $findingQuery->count();
        $findings = $findingQuery
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn (PullRequestReviewFinding $f) => [
                'id'              => $f->id,
                'title'           => $f->title,
                'severity'        => $f->severity?->value,
                'category'        => $f->category?->value,
                'file'            => $f->file,
                'line'            => $f->line,
                'confidence'      => $f->confidence,
                'explanation'     => $f->explanation,
                'suggested_fix'   => $f->suggested_fix,
                'resolved_at'     => $f->resolved_at,
                'resolution_type' => $f->resolution_type?->value,
                'created_at'      => $f->created_at,
                'repository'      => $f->repository ? [
                    'id'        => $f->repository->id,
                    'name'      => $f->repository->name,
                    'full_name' => $f->repository->full_name,
                ] : null,
                'pull_request'    => $f->pullRequest ? [
                    'number'  => $f->pullRequest->number,
                    'title'   => $f->pullRequest->title,
                    'web_url' => $f->pullRequest->web_url,
                    'state'   => $f->pullRequest->state?->value,
                ] : null,
            ]);

        // ── Stats (global, unaffected by severity/status/search filters) ─────
        $statsBase = DB::table('pull_request_review_findings')
            ->when($repoId, fn ($q) => $q->where('git_repository_id', $repoId))
            ->when($authorLogin, fn ($q) => $q->whereIn('pull_request_id', function ($sub) use ($authorLogin) {
                $sub->select('id')->from('pull_requests')->where('author_login', $authorLogin);
            }));

        $stats = (clone $statsBase)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN severity = 'critical'      THEN 1 ELSE 0 END) as critical,
            SUM(CASE WHEN severity = 'high'           THEN 1 ELSE 0 END) as high,
            SUM(CASE WHEN severity = 'medium'         THEN 1 ELSE 0 END) as medium,
            SUM(CASE WHEN severity = 'low'            THEN 1 ELSE 0 END) as low,
            SUM(CASE WHEN severity = 'informational'  THEN 1 ELSE 0 END) as informational,
            SUM(CASE WHEN resolved_at IS NOT NULL     THEN 1 ELSE 0 END) as resolved,
            SUM(CASE WHEN resolved_at IS NULL         THEN 1 ELSE 0 END) as open_count
        ")->first();

        // ── Top categories ────────────────────────────────────────────────────
        $topCategories = (clone $statsBase)
            ->select('category', DB::raw('COUNT(*) as count'))
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        // ── 30-day trend (fill gaps with zero) ───────────────────────────────
        $rawTrend = (clone $statsBase)
            ->selectRaw("CAST(created_at AS DATE) as date, COUNT(*) as count")
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->groupByRaw("CAST(created_at AS DATE)")
            ->orderBy('date')
            ->pluck('count', 'date');

        $trend = collect(range(29, 0))->map(function (int $i) use ($rawTrend) {
            $date = now()->subDays($i)->format('Y-m-d');
            return ['date' => $date, 'count' => (int) ($rawTrend[$date] ?? 0)];
        })->values();

        // ── Filter options ────────────────────────────────────────────────────
        $repositories = DB::table('git_repositories as r')
            ->join('pull_request_review_findings as f', 'f.git_repository_id', '=', 'r.id')
            ->select('r.id', 'r.name', 'r.full_name')
            ->distinct()
            ->orderBy('r.full_name')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'full_name' => $r->full_name]);

        $developers = DB::table('pull_requests as pr')
            ->join('pull_request_review_findings as f', 'f.pull_request_id', '=', 'pr.id')
            ->select('pr.author_login', 'pr.author_name', 'pr.author_avatar_url')
            ->whereNotNull('pr.author_login')
            ->when($repoId, fn ($q) => $q->where('f.git_repository_id', $repoId))
            ->distinct()
            ->orderBy('pr.author_name')
            ->get()
            ->map(fn ($d) => ['login' => $d->author_login, 'name' => $d->author_name, 'avatar' => $d->author_avatar_url]);

        $categories = DB::table('pull_request_review_findings')
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->orderBy('category')
            ->pluck('category');

        $resolutionTypes = array_map(
            fn (FindingResolutionType $t) => ['value' => $t->value, 'label' => $t->label()],
            FindingResolutionType::cases(),
        );

        return Inertia::render('findings/index', [
            'findings'         => $findings,
            'total'            => $total,
            'page'             => $page,
            'per_page'         => $perPage,
            'stats'            => [
                'total'         => (int) ($stats->total ?? 0),
                'critical'      => (int) ($stats->critical ?? 0),
                'high'          => (int) ($stats->high ?? 0),
                'medium'        => (int) ($stats->medium ?? 0),
                'low'           => (int) ($stats->low ?? 0),
                'informational' => (int) ($stats->informational ?? 0),
                'resolved'      => (int) ($stats->resolved ?? 0),
                'open'          => (int) ($stats->open_count ?? 0),
            ],
            'top_categories'   => $topCategories,
            'trend'            => $trend,
            'repositories'     => $repositories,
            'developers'       => $developers,
            'categories'       => $categories,
            'resolution_types' => $resolutionTypes,
            'filters'          => [
                'repository_id' => $repoId,
                'severity'      => $severity,
                'category'      => $category,
                'status'        => $status,
                'search'        => $search,
                'sort_by'       => $sortBy,
                'author_login'  => $authorLogin,
            ],
        ]);
    }
}
