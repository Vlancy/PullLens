import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bug,
    CheckCircle,
    Clock,
    Eye,
    Flame,
    GitMerge,
    GitPullRequest,
    Link2,
    Server,
    Timer,
    Trophy,
    XCircle,
} from 'lucide-react';
import type { ElementType } from 'react';
import { useMemo, useState } from 'react';
import { ReportsNav } from '@/components/reports-nav';

// ─── Types ────────────────────────────────────────────────────────────────────

type Stats = {
    total_prs: number;
    open_prs: number;
    merged_prs: number;
    total_reviews: number;
    total_findings: number;
    active_repos: number;
    connected_accounts: number;
    critical_findings: number;
    high_risk_prs: number;
    avg_review_duration_ms: number;
    resolved_findings: number;
    request_changes_reviews: number;
};

type Author = {
    author_login: string;
    author_name: string | null;
};

type LeaderboardEntry = {
    author_login: string;
    author_name: string | null;
    author_avatar_url: string | null;
    total_prs: number;
    merged_prs: number;
    commits: number;
    findings: number;
};

type SortKey = 'total_prs' | 'merged_prs' | 'commits' | 'findings';

type Period = 'today' | '7d' | '30d' | '90d' | 'all';

const PERIODS: { value: Period; label: string }[] = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
    { value: 'all', label: 'All time' },
];

const SORT_OPTIONS: { key: SortKey; label: string }[] = [
    { key: 'total_prs', label: 'PRs Opened' },
    { key: 'merged_prs', label: 'PRs Merged' },
    { key: 'commits', label: 'Commits' },
    { key: 'findings', label: 'Findings' },
];

type Props = {
    stats: Stats;
    period: Period;
    author: string | null;
    authors: Author[];
    leaderboard: LeaderboardEntry[];
};

// ─── Stat card ────────────────────────────────────────────────────────────────

function StatCard({
    label,
    value,
    icon: Icon,
    iconColor,
}: {
    label: string;
    value: string | number;
    icon: ElementType;
    iconColor?: string;
}) {
    return (
        <div className="rounded-lg border bg-card p-4 shadow-sm">
            <div className="flex items-center justify-between">
                <p className="text-sm font-medium text-muted-foreground">
                    {label}
                </p>
                <Icon
                    className={`size-4 ${iconColor ?? 'text-muted-foreground'}`}
                />
            </div>
            <p className="mt-2 text-3xl font-bold tabular-nums">{value}</p>
        </div>
    );
}

// ─── Leaderboard helpers ──────────────────────────────────────────────────────

function RankBadge({ rank }: { rank: number }) {
    if (rank === 1) {
        return (
            <span className="inline-flex size-6 items-center justify-center rounded-full bg-amber-400 text-xs font-bold text-white">
                1
            </span>
        );
    }

    if (rank === 2) {
        return (
            <span className="inline-flex size-6 items-center justify-center rounded-full bg-slate-300 text-xs font-bold text-slate-700 dark:bg-slate-600 dark:text-slate-200">
                2
            </span>
        );
    }

    if (rank === 3) {
        return (
            <span className="inline-flex size-6 items-center justify-center rounded-full bg-amber-700 text-xs font-bold text-white">
                3
            </span>
        );
    }

    return (
        <span className="inline-flex size-6 items-center justify-center text-xs font-medium text-muted-foreground">
            {rank}
        </span>
    );
}

function DevAvatar({
    login,
    name,
    url,
}: {
    login: string;
    name: string | null;
    url: string | null;
}) {
    if (url) {
        return (
            <img
                src={url}
                alt={name ?? login}
                className="size-7 rounded-full"
            />
        );
    }

    const initials = (name ?? login).slice(0, 2).toUpperCase();

    return (
        <span className="inline-flex size-7 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground">
            {initials}
        </span>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsOverview({
    stats,
    period,
    author,
    authors,
    leaderboard,
}: Props) {
    const showBanner = stats.critical_findings > 0 || stats.high_risk_prs > 0;
    const [sortBy, setSortBy] = useState<SortKey>('total_prs');

    const sortedLeaderboard = useMemo(
        () => [...leaderboard].sort((a, b) => b[sortBy] - a[sortBy]),
        [leaderboard, sortBy],
    );

    function setPeriod(value: Period) {
        router.get(
            '/reports',
            { period: value, author: author ?? undefined },
            { preserveState: true, replace: true },
        );
    }

    function setAuthor(login: string) {
        router.get(
            '/reports',
            { period, author: login || undefined },
            { preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Reports - Overview" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Engineering Overview
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            System-wide health snapshot across all repositories
                            and developers
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {authors.length > 0 && (
                            <select
                                value={author ?? ''}
                                onChange={(e) => setAuthor(e.target.value)}
                                className="rounded-md border border-border bg-background px-3 py-1.5 text-sm text-foreground shadow-sm focus:ring-2 focus:ring-ring focus:outline-none"
                            >
                                <option value="">All developers</option>
                                {authors.map((a) => (
                                    <option
                                        key={a.author_login}
                                        value={a.author_login}
                                    >
                                        {a.author_name || a.author_login}
                                    </option>
                                ))}
                            </select>
                        )}

                        <div className="flex items-center gap-1 rounded-lg border bg-muted/40 p-1">
                            {PERIODS.map(({ value, label }) => (
                                <button
                                    key={value}
                                    onClick={() => setPeriod(value)}
                                    className={[
                                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                        period === value
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    ].join(' ')}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                <ReportsNav active="/reports" />

                {showBanner && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400">
                        <strong>Attention needed:</strong>{' '}
                        {stats.critical_findings} critical finding
                        {stats.critical_findings !== 1 ? 's' : ''} and{' '}
                        {stats.high_risk_prs} high-risk PR
                        {stats.high_risk_prs !== 1 ? 's' : ''} require review.
                    </div>
                )}

                {/* Section 1 - Pull Request Activity */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                        Pull Request Activity
                    </h2>
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard
                            label="Total PRs"
                            value={stats.total_prs.toLocaleString()}
                            icon={GitPullRequest}
                        />
                        <StatCard
                            label="Open PRs"
                            value={stats.open_prs.toLocaleString()}
                            icon={Clock}
                            iconColor={
                                stats.open_prs > 5
                                    ? 'text-yellow-500'
                                    : undefined
                            }
                        />
                        <StatCard
                            label="Merged PRs"
                            value={stats.merged_prs.toLocaleString()}
                            icon={GitMerge}
                            iconColor="text-green-500"
                        />
                        <StatCard
                            label="High Risk PRs"
                            value={stats.high_risk_prs.toLocaleString()}
                            icon={AlertTriangle}
                            iconColor={
                                stats.high_risk_prs > 0
                                    ? 'text-red-500'
                                    : undefined
                            }
                        />
                    </div>
                </div>

                {/* Section 2 - Code Quality */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                        Code Quality
                    </h2>
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard
                            label="Total Findings"
                            value={stats.total_findings.toLocaleString()}
                            icon={Bug}
                        />
                        <StatCard
                            label="Critical Findings"
                            value={stats.critical_findings.toLocaleString()}
                            icon={Flame}
                            iconColor={
                                stats.critical_findings > 0
                                    ? 'text-red-500'
                                    : undefined
                            }
                        />
                        <StatCard
                            label="Resolved Findings"
                            value={stats.resolved_findings.toLocaleString()}
                            icon={CheckCircle}
                            iconColor="text-green-500"
                        />
                        <StatCard
                            label="Reviews Requesting Changes"
                            value={stats.request_changes_reviews.toLocaleString()}
                            icon={XCircle}
                            iconColor={
                                stats.request_changes_reviews > 0
                                    ? 'text-orange-500'
                                    : undefined
                            }
                        />
                    </div>
                </div>

                {/* Section 3 - System Health */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                        System Health
                    </h2>
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard
                            label="Active Repos"
                            value={stats.active_repos.toLocaleString()}
                            icon={Server}
                        />
                        <StatCard
                            label="Connected Accounts"
                            value={stats.connected_accounts.toLocaleString()}
                            icon={Link2}
                        />
                        <StatCard
                            label="Total Reviews"
                            value={stats.total_reviews.toLocaleString()}
                            icon={Eye}
                        />
                        <StatCard
                            label="Avg Review Time"
                            value={`${Math.round(stats.avg_review_duration_ms / 1000)}s`}
                            icon={Timer}
                        />
                    </div>
                </div>

                {/* Section 4 - Developer Leaderboard */}
                {leaderboard.length > 0 && (
                    <div className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="flex items-center gap-2 text-sm font-semibold tracking-wider text-muted-foreground uppercase">
                                <Trophy className="size-3.5" />
                                Developer Leaderboard
                            </h2>
                            <div className="flex gap-1">
                                {SORT_OPTIONS.map((opt) => (
                                    <button
                                        key={opt.key}
                                        onClick={() => setSortBy(opt.key)}
                                        className={[
                                            'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                            sortBy === opt.key
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground hover:text-foreground',
                                        ].join(' ')}
                                    >
                                        {opt.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="rounded-lg border bg-card shadow-sm">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                            <th className="w-8 px-4 py-3">#</th>
                                            <th className="px-4 py-3">
                                                Developer
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                PRs Opened
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                PRs Merged
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Commits
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Findings
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {sortedLeaderboard.map((entry, i) => (
                                            <tr
                                                key={entry.author_login}
                                                className="hover:bg-muted/40"
                                            >
                                                <td className="px-4 py-3">
                                                    <RankBadge rank={i + 1} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2.5">
                                                        <DevAvatar
                                                            login={
                                                                entry.author_login
                                                            }
                                                            name={
                                                                entry.author_name
                                                            }
                                                            url={
                                                                entry.author_avatar_url
                                                            }
                                                        />
                                                        <div className="min-w-0">
                                                            <p className="truncate font-medium">
                                                                {entry.author_name ||
                                                                    entry.author_login}
                                                            </p>
                                                            {entry.author_name && (
                                                                <p className="truncate text-xs text-muted-foreground">
                                                                    {
                                                                        entry.author_login
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    <span
                                                        className={
                                                            sortBy ===
                                                            'total_prs'
                                                                ? 'font-semibold'
                                                                : ''
                                                        }
                                                    >
                                                        {entry.total_prs}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    <span
                                                        className={
                                                            sortBy ===
                                                            'merged_prs'
                                                                ? 'font-semibold text-purple-600 dark:text-purple-400'
                                                                : ''
                                                        }
                                                    >
                                                        {entry.merged_prs}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    <span
                                                        className={
                                                            sortBy === 'commits'
                                                                ? 'font-semibold'
                                                                : ''
                                                        }
                                                    >
                                                        {entry.commits.toLocaleString()}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    <span
                                                        className={[
                                                            sortBy ===
                                                            'findings'
                                                                ? 'font-semibold'
                                                                : '',
                                                            entry.findings > 0
                                                                ? 'text-amber-600 dark:text-amber-400'
                                                                : 'text-muted-foreground',
                                                        ].join(' ')}
                                                    >
                                                        {entry.findings > 0
                                                            ? entry.findings
                                                            : '-'}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

ReportsOverview.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Overview', href: '/reports' },
    ],
};
