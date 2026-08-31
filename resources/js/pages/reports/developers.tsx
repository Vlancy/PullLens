import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    CalendarDays,
    GitBranch,
    GitCommitHorizontal,
    LayoutDashboard,
    Users,
} from 'lucide-react';
import { useState, useMemo } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

// ─── Types ────────────────────────────────────────────────────────────────────

type Developer = {
    author_login: string;
    author_name: string | null;
    author_avatar_url: string | null;
    total_prs: number;
    merged_prs: number;
    total_additions: number;
    total_deletions: number;
    total_commits: number;
    total_findings: number;
    resolved_findings_count: number;
    resolution_rate: number | null;
    findings_by_severity: {
        critical: number;
        high: number;
        medium: number;
        low: number;
        false_positive: number;
    };
    seniority_score: number | null;
    seniority_level: 'Junior' | 'Mid' | 'Senior' | 'Expert' | null;
    avg_merge_hours: number | null;
    avg_estimated_hours: number | null;
    request_changes_count: number;
    avg_time_to_first_review_hours: number | null;
};

type Repo = {
    id: string;
    name: string;
    full_name: string;
};

type WeeklyVelocity = {
    week: string;
    opened: number;
    merged: number;
};

type Props = {
    developers: Developer[];
    period: string;
    repo_id: string | null;
    repositories: Repo[];
    weekly_velocity: WeeklyVelocity[];
    sync_commit_stats_url: string;
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function seniorityBadgeClass(level: NonNullable<Developer['seniority_level']>): string {
    switch (level) {
        case 'Junior':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
        case 'Mid':
            return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400';
        case 'Senior':
            return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400';
        case 'Expert':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400';
    }
}

function formatHours(hours: number | null): string {
    if (hours === null) {
return '—';
}

    if (hours < 24) {
return `${hours}h`;
}

    return `${Math.round(hours / 24)}d`;
}

// ─── Sub-nav ──────────────────────────────────────────────────────────────────

function ReportsNav({ active }: { active: string }) {
    const tabs = [
        { icon: LayoutDashboard, label: 'Overview',       href: '/reports' },
        { icon: Users,           label: 'Team',            href: '/reports/developers' },
        { icon: GitBranch,       label: 'Repos',           href: '/reports/repositories' },
        { icon: GitCommitHorizontal, label: 'Commit Quality', href: '/reports/commits' },
        { icon: CalendarDays,    label: 'Daily Activity',  href: '/reports/daily' },
        { icon: Activity,        label: 'Daily Effort',    href: '/reports/developer-daily' },
    ];

    return (
        <div className="flex gap-0.5 border-b border-border">
            {tabs.map((tab) => (
                <Link
                    key={tab.href}
                    href={tab.href}
                    className={[
                        'flex items-center gap-1.5 px-3 py-2 text-sm font-medium transition-colors rounded-t-md',
                        active === tab.href
                            ? 'border-b-2 border-primary text-foreground bg-background'
                            : 'text-muted-foreground hover:text-foreground hover:bg-muted/50',
                    ].join(' ')}
                >
                    <tab.icon className="size-3.5 shrink-0" />
                    {tab.label}
                </Link>
            ))}
        </div>
    );
}

// ─── Period filter ────────────────────────────────────────────────────────────

const periodOptions = [
    { label: 'Today', value: 'today' },
    { label: '7 days', value: '7d' },
    { label: '30 days', value: '30d' },
    { label: '90 days', value: '90d' },
    { label: 'All time', value: 'all' },
];

function PeriodTabs({
    current,
    onChange,
}: {
    current: string;
    onChange: (p: string) => void;
}) {
    return (
        <div className="flex flex-wrap gap-1">
            {periodOptions.map((opt) => (
                <button
                    key={opt.value}
                    onClick={() => onChange(opt.value)}
                    className={[
                        'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                        current === opt.value
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted text-muted-foreground hover:bg-muted/80 hover:text-foreground',
                    ].join(' ')}
                >
                    {opt.label}
                </button>
            ))}
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsDevelopers({ developers, period, repo_id, repositories, weekly_velocity, sync_commit_stats_url }: Props) {
    const [search, setSearch] = useState('');
    const [syncing, setSyncing] = useState(false);

    function handlePeriodChange(p: string) {
        router.get('/reports/developers', { period: p, repo_id: repo_id ?? undefined }, { preserveState: false });
    }

    function handleRepoChange(id: string) {
        router.get('/reports/developers', { period, repo_id: id || undefined }, { preserveState: false });
    }

    function handleSyncCommitStats() {
        setSyncing(true);
        router.post(sync_commit_stats_url, {}, {
            onFinish: () => {
                setSyncing(false);
                router.reload();
            },
        });
    }

    const filtered = useMemo(() => {
        if (!search.trim()) {
return developers;
}

        const q = search.toLowerCase();

        return developers.filter(
            (d) =>
                d.author_login.toLowerCase().includes(q) ||
                (d.author_name ?? '').toLowerCase().includes(q),
        );
    }, [developers, search]);

    const needingAttention = developers.filter(
        (d) => d.seniority_level === 'Junior' && d.seniority_level !== null,
    ).length;

    const leadSeniorCount = developers.filter(
        (d) => d.seniority_level === 'Expert' || d.seniority_level === 'Senior',
    ).length;

    return (
        <>
            <Head title="Reports — Developers" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Team Performance</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        PR output, code quality, and seniority level per developer
                    </p>
                </div>

                <ReportsNav active="/reports/developers" />

                <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <PeriodTabs current={period} onChange={handlePeriodChange} />
                    {repositories.length > 0 && (
                        <select
                            value={repo_id ?? ''}
                            onChange={(e) => handleRepoChange(e.target.value)}
                            className="rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="">All repositories</option>
                            {repositories.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.full_name}
                                </option>
                            ))}
                        </select>
                    )}
                    <input
                        type="search"
                        placeholder="Search developer…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground shadow-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                    />
                </div>
                    <button
                        onClick={handleSyncCommitStats}
                        disabled={syncing}
                        className="rounded-md border border-input bg-background px-3 py-1.5 text-sm text-muted-foreground shadow-sm transition-colors hover:bg-muted hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {syncing ? 'Syncing…' : 'Sync commits'}
                    </button>
                </div>

                {filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <p className="text-sm">
                            No developer data for this period.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
                            <span><strong className="text-foreground">{developers.length}</strong> developers</span>
                            {needingAttention > 0 && (
                                <span><strong className="text-red-600 dark:text-red-400">{needingAttention}</strong> needing attention</span>
                            )}
                            <span><strong className="text-foreground">{leadSeniorCount}</strong> expert/senior</span>
                        </div>

                        {/* ── Overview charts ────────────────────────────────── */}
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            {/* Team weekly velocity */}
                            <Card>
                                <CardContent className="p-4">
                                    <p className="mb-3 text-xs font-medium text-muted-foreground uppercase tracking-wide">Team Weekly Velocity</p>
                                    {(() => {
                                        const maxV = Math.max(...weekly_velocity.map(w => Math.max(w.opened, w.merged)), 1);

                                        return (
                                            <div className="flex items-end gap-1.5 h-24">
                                                {weekly_velocity.map((w, i) => (
                                                    <div key={i} className="flex flex-1 flex-col items-center gap-0.5">
                                                        <div className="flex w-full items-end gap-0.5 justify-center" style={{ height: '80px' }}>
                                                            <div
                                                                className="flex-1 rounded-sm bg-primary/30"
                                                                style={{ height: `${Math.max((w.opened / maxV) * 80, w.opened > 0 ? 3 : 0)}px` }}
                                                                title={`Opened: ${w.opened}`}
                                                            />
                                                            <div
                                                                className="flex-1 rounded-sm bg-primary"
                                                                style={{ height: `${Math.max((w.merged / maxV) * 80, w.merged > 0 ? 3 : 0)}px` }}
                                                                title={`Merged: ${w.merged}`}
                                                            />
                                                        </div>
                                                        <span className="text-[9px] text-muted-foreground">
                                                            {new Date(w.week + 'T00:00:00').toLocaleDateString('en', { month: 'short', day: 'numeric' })}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        );
                                    })()}
                                    <div className="mt-2 flex gap-3 text-xs text-muted-foreground">
                                        <span className="flex items-center gap-1"><span className="inline-block size-2 rounded-sm bg-primary/30" /> Opened</span>
                                        <span className="flex items-center gap-1"><span className="inline-block size-2 rounded-sm bg-primary" /> Merged</span>
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Top contributors by code */}
                            <Card>
                                <CardContent className="p-4">
                                    <p className="mb-3 text-xs font-medium text-muted-foreground uppercase tracking-wide">Top Contributors (Lines Added)</p>
                                    {(() => {
                                        const top = [...developers]
                                            .sort((a, b) => b.total_additions - a.total_additions)
                                            .slice(0, 6);
                                        const maxA = Math.max(...top.map(d => d.total_additions), 1);

                                        return (
                                            <div className="flex flex-col gap-2">
                                                {top.map(dev => (
                                                    <button
                                                        key={dev.author_login}
                                                        className="group flex items-center gap-2 text-left"
                                                        onClick={() => router.get(`/reports/developers/${dev.author_login}`)}
                                                    >
                                                        <span className="w-20 shrink-0 truncate text-xs text-muted-foreground group-hover:text-foreground">
                                                            {dev.author_name ?? dev.author_login}
                                                        </span>
                                                        <div className="flex-1 overflow-hidden rounded-full bg-muted h-2">
                                                            <div
                                                                className="h-full rounded-full bg-green-500/70"
                                                                style={{ width: `${(dev.total_additions / maxA) * 100}%` }}
                                                            />
                                                        </div>
                                                        <span className="w-12 shrink-0 text-right text-xs tabular-nums text-muted-foreground">
                                                            +{dev.total_additions.toLocaleString()}
                                                        </span>
                                                    </button>
                                                ))}
                                            </div>
                                        );
                                    })()}
                                </CardContent>
                            </Card>
                        </div>

                        <Card>
                            <CardContent className="p-0">
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                                <th className="px-4 py-3">Developer</th>
                                                <th className="px-4 py-3">
                                                    <TooltipProvider><Tooltip>
                                                        <TooltipTrigger className="underline decoration-dotted cursor-help">PRs</TooltipTrigger>
                                                        <TooltipContent className="max-w-52 text-center">PRs the developer contributed commits to, and how many were merged.</TooltipContent>
                                                    </Tooltip></TooltipProvider>
                                                </th>
                                                <th className="px-4 py-3">
                                                    <TooltipProvider><Tooltip>
                                                        <TooltipTrigger className="underline decoration-dotted cursor-help">Code</TooltipTrigger>
                                                        <TooltipContent className="max-w-52 text-center">Lines added and removed across all personal commits in the period.</TooltipContent>
                                                    </Tooltip></TooltipProvider>
                                                </th>
                                                <th className="px-4 py-3">
                                                    <TooltipProvider><Tooltip>
                                                        <TooltipTrigger className="underline decoration-dotted cursor-help">Avg effort/PR</TooltipTrigger>
                                                        <TooltipContent className="max-w-56 text-center">AI-estimated programming hours per PR. Attributed to the developer who wrote the most code in each PR.</TooltipContent>
                                                    </Tooltip></TooltipProvider>
                                                </th>
                                                <th className="px-4 py-3">
                                                    <TooltipProvider><Tooltip>
                                                        <TooltipTrigger className="underline decoration-dotted cursor-help">Avg 1st review</TooltipTrigger>
                                                        <TooltipContent className="max-w-52 text-center">Average time from PR opened to first AI review.</TooltipContent>
                                                    </Tooltip></TooltipProvider>
                                                </th>
                                                <th className="px-4 py-3">
                                                    <TooltipProvider><Tooltip>
                                                        <TooltipTrigger className="underline decoration-dotted cursor-help">Findings</TooltipTrigger>
                                                        <TooltipContent className="max-w-56 text-center">Code issues flagged by AI review. C=Critical, H=High, M=Medium, L=Low. Fix % = resolved real findings. Attributed to the PR's primary author.</TooltipContent>
                                                    </Tooltip></TooltipProvider>
                                                </th>
                                                <th className="px-4 py-3">
                                                    <TooltipProvider><Tooltip>
                                                        <TooltipTrigger className="underline decoration-dotted cursor-help">Seniority</TooltipTrigger>
                                                        <TooltipContent className="max-w-64 text-center">Composite score: code quality 60% (weighted finding rate per reviewed PR), fix rate 25% (resolved findings), review verdict 15% (request-changes rate). Requires 3+ reviewed PRs.</TooltipContent>
                                                    </Tooltip></TooltipProvider>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-border">
                                            {filtered.map((dev) => {
                                                const initials = (
                                                    dev.author_name ??
                                                    dev.author_login
                                                )
                                                    .slice(0, 2)
                                                    .toUpperCase();

                                                const isAtRisk =
                                                    dev.seniority_level === 'Junior' &&
                                                    dev.total_findings > 0;

                                                return (
                                                    <tr
                                                        key={dev.author_login}
                                                        className={[
                                                            'hover:bg-muted/40 cursor-pointer',
                                                            isAtRisk
                                                                ? 'bg-red-50/40 dark:bg-red-950/10'
                                                                : '',
                                                        ].join(' ')}
                                                        onClick={() => router.get(`/reports/developers/${dev.author_login}`)}
                                                    >
                                                        {/* Developer */}
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-center gap-2.5">
                                                                <Avatar className="size-7 shrink-0">
                                                                    <AvatarImage
                                                                        src={
                                                                            dev.author_avatar_url ??
                                                                            undefined
                                                                        }
                                                                    />
                                                                    <AvatarFallback className="text-xs">
                                                                        {initials}
                                                                    </AvatarFallback>
                                                                </Avatar>
                                                                <div className="min-w-0">
                                                                    <p className="truncate font-medium leading-snug">
                                                                        {dev.author_name ??
                                                                            dev.author_login}
                                                                    </p>
                                                                    {dev.author_name && (
                                                                        <p className="truncate text-xs text-muted-foreground">
                                                                            @{dev.author_login}
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        </td>

                                                        {/* PRs */}
                                                        <td className="px-4 py-3 tabular-nums">
                                                            <div className="flex flex-wrap items-center gap-1">
                                                                <span className="font-medium">
                                                                    {dev.total_prs}
                                                                </span>
                                                                <span className="text-muted-foreground">
                                                                    / {dev.merged_prs} merged
                                                                </span>
                                                            </div>
                                                        </td>

                                                        {/* Code */}
                                                        <td className="px-4 py-3 tabular-nums">
                                                            <span className="text-green-600 dark:text-green-400">
                                                                +{dev.total_additions.toLocaleString()}
                                                            </span>{' '}
                                                            <span className="text-red-600 dark:text-red-400">
                                                                −{dev.total_deletions.toLocaleString()}
                                                            </span>
                                                        </td>

                                                        {/* Avg estimated programming effort */}
                                                        <td className="px-4 py-3 tabular-nums">
                                                            {dev.avg_estimated_hours === null ? (
                                                                <span className="text-muted-foreground">—</span>
                                                            ) : (
                                                                <div>
                                                                    <span className="font-medium">
                                                                        {formatHours(dev.avg_estimated_hours)}
                                                                    </span>
                                                                    <p className="text-[10px] text-muted-foreground/60">
                                                                        AI estimate
                                                                    </p>
                                                                </div>
                                                            )}
                                                        </td>

                                                        {/* Avg time to first review */}
                                                        <td className="px-4 py-3 tabular-nums text-muted-foreground">
                                                            {formatHours(
                                                                dev.avg_time_to_first_review_hours,
                                                            )}
                                                        </td>

                                                        {/* Findings */}
                                                        <td className="px-4 py-3">
                                                            <div className="flex flex-col gap-0.5">
                                                                <div className="flex flex-wrap items-center gap-1">
                                                                    {dev.total_findings === 0 ? (
                                                                        <span className="text-xs text-green-600 dark:text-green-400">Clean</span>
                                                                    ) : (
                                                                        <>
                                                                            {dev.findings_by_severity.critical > 0 && (
                                                                                <span className="inline-block size-2 rounded-full bg-red-500 mr-0.5" />
                                                                            )}
                                                                            <span className="tabular-nums font-medium">
                                                                                {dev.total_findings}
                                                                            </span>
                                                                            <TooltipProvider>
                                                                            {dev.findings_by_severity.critical > 0 && (
                                                                                <Tooltip>
                                                                                    <TooltipTrigger asChild>
                                                                                        <Badge className="h-4 bg-red-100 px-1 py-0 text-[10px] text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                                                                            {dev.findings_by_severity.critical}C
                                                                                        </Badge>
                                                                                    </TooltipTrigger>
                                                                                    <TooltipContent>{dev.findings_by_severity.critical} Critical</TooltipContent>
                                                                                </Tooltip>
                                                                            )}
                                                                            {dev.findings_by_severity.high > 0 && (
                                                                                <Tooltip>
                                                                                    <TooltipTrigger asChild>
                                                                                        <Badge className="h-4 bg-orange-100 px-1 py-0 text-[10px] text-orange-700 dark:bg-orange-900/40 dark:text-orange-400">
                                                                                            {dev.findings_by_severity.high}H
                                                                                        </Badge>
                                                                                    </TooltipTrigger>
                                                                                    <TooltipContent>{dev.findings_by_severity.high} High</TooltipContent>
                                                                                </Tooltip>
                                                                            )}
                                                                            {dev.findings_by_severity.medium > 0 && (
                                                                                <Tooltip>
                                                                                    <TooltipTrigger asChild>
                                                                                        <Badge className="h-4 bg-yellow-100 px-1 py-0 text-[10px] text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400">
                                                                                            {dev.findings_by_severity.medium}M
                                                                                        </Badge>
                                                                                    </TooltipTrigger>
                                                                                    <TooltipContent>{dev.findings_by_severity.medium} Medium</TooltipContent>
                                                                                </Tooltip>
                                                                            )}
                                                                            {dev.findings_by_severity.low > 0 && (
                                                                                <Tooltip>
                                                                                    <TooltipTrigger asChild>
                                                                                        <Badge className="h-4 bg-blue-100 px-1 py-0 text-[10px] text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">
                                                                                            {dev.findings_by_severity.low}L
                                                                                        </Badge>
                                                                                    </TooltipTrigger>
                                                                                    <TooltipContent>{dev.findings_by_severity.low} Low</TooltipContent>
                                                                                </Tooltip>
                                                                            )}
                                                                            {dev.findings_by_severity.false_positive > 0 && (
                                                                                <Tooltip>
                                                                                    <TooltipTrigger asChild>
                                                                                        <Badge className="h-4 bg-gray-100 px-1 py-0 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                                                                            {dev.findings_by_severity.false_positive}FP
                                                                                        </Badge>
                                                                                    </TooltipTrigger>
                                                                                    <TooltipContent>{dev.findings_by_severity.false_positive} False positive — not counted in seniority score</TooltipContent>
                                                                                </Tooltip>
                                                                            )}
                                                                            </TooltipProvider>
                                                                        </>
                                                                    )}
                                                                </div>
                                                                {dev.resolution_rate !== null && (
                                                                    <span className={[
                                                                        'text-[10px] tabular-nums',
                                                                        dev.resolution_rate >= 75
                                                                            ? 'text-green-600 dark:text-green-400'
                                                                            : dev.resolution_rate >= 25
                                                                            ? 'text-amber-600 dark:text-amber-400'
                                                                            : 'text-muted-foreground',
                                                                    ].join(' ')}>
                                                                        {dev.resolution_rate}% fixed
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>

                                                        {/* Seniority */}
                                                        <td className="px-4 py-3">
                                                            {dev.seniority_level === null ? (
                                                                <span className="text-xs text-muted-foreground">—</span>
                                                            ) : (
                                                                <div className="flex items-center gap-1.5">
                                                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${seniorityBadgeClass(dev.seniority_level)}`}>
                                                                        {dev.seniority_level}
                                                                    </span>
                                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                                        {dev.seniority_score}
                                                                    </span>
                                                                </div>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}

ReportsDevelopers.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Developers', href: '/reports/developers' },
    ],
};
