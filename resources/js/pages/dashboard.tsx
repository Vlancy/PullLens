import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Bot,
    CircleDot,
    Clock,
    Database,
    FileDiff,
    GitMerge,
    GitPullRequest,
    ShieldAlert,
    XCircle,
} from 'lucide-react';
import type { ElementType } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';

// ─── Types ───────────────────────────────────────────────────────────────────

type Stats = {
    total_repositories: number;
    total_prs: number;
    open_prs: number;
    merged_prs: number;
    draft_prs: number;
    closed_prs: number;
    total_reviews: number;
    avg_review_duration_ms: number | null;
    total_findings: number;
    critical_high_findings: number;
};

type RecentReview = {
    id: string;
    verdict: string | null;
    verdict_label: string | null;
    risk_level: string | null;
    ai_model: string | null;
    review_duration_ms: number | null;
    reviewed_at: string | null;
    findings_count: number;
    pull_request: {
        number: number;
        title: string;
        author_login: string | null;
        author_avatar_url: string | null;
        web_url: string | null;
        state: string | null;
        repository: { full_name: string } | null;
    } | null;
};

type TopRepository = {
    id: string;
    full_name: string;
    web_url: string | null;
    provider: string | null;
    reviews_enabled: boolean;
    pull_requests_count: number;
    findings_count: number;
};

type Props = {
    stats: Stats;
    findings_by_severity: Record<string, number>;
    findings_by_category: Record<string, number>;
    verdict_distribution: Record<string, number>;
    recent_reviews: RecentReview[];
    top_repositories: TopRepository[];
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatDuration(ms: number | null): string {
    if (!ms) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60_000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60_000)}m ${Math.floor((ms % 60_000) / 1000)}s`;
}

function timeAgo(iso: string | null): string {
    if (!iso) return '—';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60_000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

function plural(n: number, word: string): string {
    return `${n} ${word}${n !== 1 ? 's' : ''}`;
}

// ─── Config maps ─────────────────────────────────────────────────────────────

const severityConfig: Record<string, { label: string; bar: string; dot: string }> = {
    critical:      { label: 'Critical',      bar: 'bg-red-500',    dot: 'bg-red-500' },
    high:          { label: 'High',          bar: 'bg-orange-500', dot: 'bg-orange-500' },
    medium:        { label: 'Medium',        bar: 'bg-yellow-500', dot: 'bg-yellow-500' },
    low:           { label: 'Low',           bar: 'bg-blue-400',   dot: 'bg-blue-400' },
    informational: { label: 'Informational', bar: 'bg-gray-400',   dot: 'bg-gray-400' },
};

const categoryConfig: Record<string, { label: string; bar: string }> = {
    security:        { label: 'Security',        bar: 'bg-red-500' },
    correctness:     { label: 'Correctness',     bar: 'bg-orange-500' },
    reliability:     { label: 'Reliability',     bar: 'bg-yellow-500' },
    performance:     { label: 'Performance',     bar: 'bg-blue-500' },
    maintainability: { label: 'Maintainability', bar: 'bg-purple-500' },
    testing:         { label: 'Testing',         bar: 'bg-green-500' },
};

const verdictConfig: Record<string, { label: string; bar: string; badge: string }> = {
    approve:         { label: 'Approved',          bar: 'bg-green-500', badge: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400 border-transparent' },
    comment:         { label: 'Commented',         bar: 'bg-yellow-500', badge: '' },
    request_changes: { label: 'Changes requested', bar: 'bg-red-500',   badge: '' },
};

// ─── Sub-components ───────────────────────────────────────────────────────────

function StatCard({
    label,
    value,
    icon: Icon,
    sub,
    accent,
    iconAccent,
}: {
    label: string;
    value: number | string;
    icon: ElementType;
    sub?: string;
    accent?: string;
    iconAccent?: string;
}) {
    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">{label}</p>
                        <p className={`mt-1 text-3xl font-semibold tracking-tight ${accent ?? 'text-foreground'}`}>
                            {value}
                        </p>
                        {sub && (
                            <p className="mt-1 text-xs text-muted-foreground">{sub}</p>
                        )}
                    </div>
                    <div className={`flex size-10 shrink-0 items-center justify-center rounded-lg ${iconAccent ?? 'bg-muted'}`}>
                        <Icon className={`size-5 ${iconAccent ? 'text-white' : 'text-muted-foreground'}`} />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function HorizontalBar({
    label,
    count,
    max,
    bar,
}: {
    label: string;
    count: number;
    max: number;
    bar: string;
}) {
    const pct = max > 0 ? Math.round((count / max) * 100) : 0;
    return (
        <div className="flex items-center gap-3">
            <span className="w-32 shrink-0 truncate text-sm text-muted-foreground">{label}</span>
            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full transition-all duration-500 ${bar}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="w-8 shrink-0 text-right text-sm font-medium tabular-nums">{count}</span>
        </div>
    );
}

function EmptyState({ message }: { message: string }) {
    return <p className="px-6 pb-6 text-sm text-muted-foreground">{message}</p>;
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function Dashboard({
    stats,
    findings_by_severity,
    findings_by_category,
    verdict_distribution,
    recent_reviews,
    top_repositories,
}: Props) {
    const totalSeverity = Object.values(findings_by_severity).reduce((a, b) => a + b, 0);
    const totalCategory = Object.values(findings_by_category).reduce((a, b) => a + b, 0);
    const totalVerdicts = Object.values(verdict_distribution).reduce((a, b) => a + b, 0);

    const hasCritical = stats.critical_high_findings > 0;

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-6">

                {/* Critical findings alert */}
                {hasCritical && (
                    <div className="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-400">
                        <ShieldAlert className="size-4 shrink-0" />
                        <span>
                            <strong>{stats.critical_high_findings}</strong> critical or high-severity{' '}
                            {stats.critical_high_findings === 1 ? 'finding requires' : 'findings require'} attention.
                        </span>
                    </div>
                )}

                {/* ── Stat cards ─────────────────────────────────────────── */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Tracked repositories"
                        value={stats.total_repositories}
                        icon={Database}
                    />
                    <StatCard
                        label="Total pull requests"
                        value={stats.total_prs}
                        icon={GitPullRequest}
                        sub={`${stats.open_prs} open · ${stats.draft_prs} draft`}
                    />
                    <StatCard
                        label="Merged PRs"
                        value={stats.merged_prs}
                        icon={GitMerge}
                        accent="text-purple-600 dark:text-purple-400"
                    />
                    <StatCard
                        label="Closed PRs"
                        value={stats.closed_prs}
                        icon={XCircle}
                    />
                    <StatCard
                        label="Open PRs"
                        value={stats.open_prs}
                        icon={CircleDot}
                        accent="text-green-600 dark:text-green-400"
                    />
                    <StatCard
                        label="Draft PRs"
                        value={stats.draft_prs}
                        icon={FileDiff}
                    />
                    <StatCard
                        label="AI reviews completed"
                        value={stats.total_reviews}
                        icon={Bot}
                        accent="text-primary"
                        sub={stats.avg_review_duration_ms ? `avg ${formatDuration(stats.avg_review_duration_ms)}` : undefined}
                    />
                    <StatCard
                        label="Total findings"
                        value={stats.total_findings}
                        icon={AlertTriangle}
                        accent={hasCritical ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'}
                        sub={hasCritical ? `${stats.critical_high_findings} critical / high` : 'no critical or high issues'}
                        iconAccent={hasCritical ? 'bg-red-500' : undefined}
                    />
                </div>

                {/* ── Breakdown charts ────────────────────────────────────── */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-4">
                            <CardTitle className="text-sm font-medium">Findings by severity</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {totalSeverity === 0 ? (
                                <p className="text-sm text-muted-foreground">No findings recorded yet.</p>
                            ) : (
                                Object.entries(severityConfig).map(([key, cfg]) => (
                                    <HorizontalBar
                                        key={key}
                                        label={cfg.label}
                                        count={findings_by_severity[key] ?? 0}
                                        max={totalSeverity}
                                        bar={cfg.bar}
                                    />
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-4">
                            <CardTitle className="text-sm font-medium">Findings by category</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {totalCategory === 0 ? (
                                <p className="text-sm text-muted-foreground">No findings recorded yet.</p>
                            ) : (
                                Object.entries(categoryConfig).map(([key, cfg]) => (
                                    <HorizontalBar
                                        key={key}
                                        label={cfg.label}
                                        count={findings_by_category[key] ?? 0}
                                        max={totalCategory}
                                        bar={cfg.bar}
                                    />
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-4">
                            <CardTitle className="text-sm font-medium">Review verdicts</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {totalVerdicts === 0 ? (
                                <p className="text-sm text-muted-foreground">No reviews posted yet.</p>
                            ) : (
                                <>
                                    {Object.entries(verdictConfig).map(([key, cfg]) => (
                                        <HorizontalBar
                                            key={key}
                                            label={cfg.label}
                                            count={verdict_distribution[key] ?? 0}
                                            max={totalVerdicts}
                                            bar={cfg.bar}
                                        />
                                    ))}
                                    <div className="mt-4 border-t border-border pt-4">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">Avg review time</span>
                                            <span className="font-medium tabular-nums">
                                                {formatDuration(stats.avg_review_duration_ms)}
                                            </span>
                                        </div>
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ── Recent reviews + top repositories ──────────────────── */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Recent reviews</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recent_reviews.length === 0 ? (
                                <EmptyState message="No reviews completed yet." />
                            ) : (
                                <div className="divide-y divide-border">
                                    {recent_reviews.map((review) => {
                                        const vc  = review.verdict ? verdictConfig[review.verdict] : null;
                                        const pr  = review.pull_request;
                                        const initials = pr?.author_login?.slice(0, 2).toUpperCase() ?? '?';

                                        return (
                                            <div key={review.id} className="flex items-start gap-3 px-6 py-3">
                                                <Avatar className="mt-0.5 size-7 shrink-0">
                                                    <AvatarImage src={pr?.author_avatar_url ?? undefined} />
                                                    <AvatarFallback className="text-xs">{initials}</AvatarFallback>
                                                </Avatar>

                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                        <span>{pr?.repository?.full_name ?? '—'}</span>
                                                        {pr?.number && (
                                                            <>
                                                                <span>·</span>
                                                                <span>#{pr.number}</span>
                                                            </>
                                                        )}
                                                    </div>
                                                    <p className="truncate text-sm font-medium leading-snug">
                                                        {pr?.web_url ? (
                                                            <a
                                                                href={pr.web_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="hover:underline"
                                                            >
                                                                {pr.title}
                                                            </a>
                                                        ) : (
                                                            pr?.title ?? '—'
                                                        )}
                                                    </p>
                                                    <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                        <span>{plural(review.findings_count, 'finding')}</span>
                                                        <span>·</span>
                                                        <span>{formatDuration(review.review_duration_ms)}</span>
                                                        <span>·</span>
                                                        <span>{timeAgo(review.reviewed_at)}</span>
                                                    </div>
                                                </div>

                                                {vc && (
                                                    <Badge
                                                        variant={review.verdict === 'request_changes' ? 'destructive' : 'secondary'}
                                                        className={`shrink-0 text-xs ${vc.badge}`}
                                                    >
                                                        {vc.label}
                                                    </Badge>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Top repositories</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {top_repositories.length === 0 ? (
                                <EmptyState message="No repositories tracked yet." />
                            ) : (
                                <div className="divide-y divide-border">
                                    {top_repositories.map((repo, index) => (
                                        <div key={repo.id} className="flex items-center gap-3 px-6 py-3">
                                            <span className="w-5 shrink-0 text-center text-sm font-medium text-muted-foreground">
                                                {index + 1}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {repo.web_url ? (
                                                        <a
                                                            href={repo.web_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="hover:underline"
                                                        >
                                                            {repo.full_name}
                                                        </a>
                                                    ) : (
                                                        repo.full_name
                                                    )}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {plural(repo.pull_requests_count, 'PR')} · {plural(repo.findings_count, 'finding')}
                                                </p>
                                            </div>
                                            {!repo.reviews_enabled && (
                                                <Badge variant="outline" className="shrink-0 text-xs">
                                                    Paused
                                                </Badge>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
