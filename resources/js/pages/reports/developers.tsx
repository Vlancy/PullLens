import { Head, Link, router } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';

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
    findings_by_severity: {
        critical: number;
        high: number;
        medium: number;
        low: number;
    };
    seniority_score: number;
    seniority_level: 'Junior' | 'Mid' | 'Senior' | 'Lead';
    avg_merge_hours: number | null;
    high_risk_prs: number;
    request_changes_count: number;
    avg_time_to_first_review_hours: number | null;
};

type Props = {
    developers: Developer[];
    period: string;
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function seniorityBadgeClass(level: Developer['seniority_level']): string {
    switch (level) {
        case 'Junior':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
        case 'Mid':
            return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400';
        case 'Senior':
            return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400';
        case 'Lead':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400';
    }
}

function formatHours(hours: number | null): string {
    if (hours === null) return '—';
    if (hours < 24) return `${hours}h`;
    return `${Math.round(hours / 24)}d`;
}

// ─── Sub-nav ──────────────────────────────────────────────────────────────────

function ReportsNav({ active }: { active: string }) {
    const tabs = [
        { label: 'Overview', href: '/reports' },
        { label: 'Developers', href: '/reports/developers' },
        { label: 'Repositories', href: '/reports/repositories' },
        { label: 'Commits', href: '/reports/commits' },
        { label: 'Daily', href: '/reports/daily' },
    ];

    return (
        <div className="flex gap-1 border-b border-border pb-0">
            {tabs.map((tab) => (
                <Link
                    key={tab.href}
                    href={tab.href}
                    className={[
                        'px-4 py-2 text-sm font-medium transition-colors',
                        active === tab.href
                            ? 'border-b-2 border-primary text-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    ].join(' ')}
                >
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

export default function ReportsDevelopers({ developers, period }: Props) {
    function handlePeriodChange(p: string) {
        router.get('/reports/developers', { period: p }, { preserveState: false });
    }

    return (
        <>
            <Head title="Reports — Developers" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold">Reports</h1>
                    <p className="text-sm text-muted-foreground">
                        Per-developer PR and code quality metrics
                    </p>
                </div>

                <ReportsNav active="/reports/developers" />

                <PeriodTabs current={period} onChange={handlePeriodChange} />

                {developers.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <p className="text-sm">
                            No developer data for this period.
                        </p>
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                            <th className="px-4 py-3">
                                                Developer
                                            </th>
                                            <th className="px-4 py-3">PRs</th>
                                            <th className="px-4 py-3">
                                                Code
                                            </th>
                                            <th className="px-4 py-3">
                                                Avg merge
                                            </th>
                                            <th className="px-4 py-3">
                                                Avg 1st review
                                            </th>
                                            <th className="px-4 py-3">
                                                Findings
                                            </th>
                                            <th className="px-4 py-3">
                                                Seniority
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {developers.map((dev) => {
                                            const initials = (
                                                dev.author_name ??
                                                dev.author_login
                                            )
                                                .slice(0, 2)
                                                .toUpperCase();

                                            return (
                                                <tr
                                                    key={dev.author_login}
                                                    className="hover:bg-muted/40"
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
                                                                        @
                                                                        {
                                                                            dev.author_login
                                                                        }
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
                                                                /{' '}
                                                                {dev.merged_prs}{' '}
                                                                merged
                                                            </span>
                                                            {dev.high_risk_prs > 0 && (
                                                                <span className="inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                                                    {dev.high_risk_prs} high-risk
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>

                                                    {/* Code */}
                                                    <td className="px-4 py-3 tabular-nums">
                                                        <span className="text-green-600 dark:text-green-400">
                                                            +
                                                            {dev.total_additions.toLocaleString()}
                                                        </span>{' '}
                                                        <span className="text-red-600 dark:text-red-400">
                                                            −
                                                            {dev.total_deletions.toLocaleString()}
                                                        </span>
                                                    </td>

                                                    {/* Avg merge */}
                                                    <td className="px-4 py-3 tabular-nums text-muted-foreground">
                                                        {formatHours(
                                                            dev.avg_merge_hours,
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
                                                        <div className="flex flex-wrap items-center gap-1">
                                                            <span className="tabular-nums font-medium">
                                                                {
                                                                    dev.total_findings
                                                                }
                                                            </span>
                                                            {dev.findings_by_severity
                                                                .critical >
                                                                0 && (
                                                                <Badge className="h-4 bg-red-100 px-1 py-0 text-[10px] text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                                                    {
                                                                        dev
                                                                            .findings_by_severity
                                                                            .critical
                                                                    }
                                                                    C
                                                                </Badge>
                                                            )}
                                                            {dev.findings_by_severity
                                                                .high > 0 && (
                                                                <Badge className="h-4 bg-orange-100 px-1 py-0 text-[10px] text-orange-700 dark:bg-orange-900/40 dark:text-orange-400">
                                                                    {
                                                                        dev
                                                                            .findings_by_severity
                                                                            .high
                                                                    }
                                                                    H
                                                                </Badge>
                                                            )}
                                                            {dev.findings_by_severity
                                                                .medium > 0 && (
                                                                <Badge className="h-4 bg-yellow-100 px-1 py-0 text-[10px] text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400">
                                                                    {
                                                                        dev
                                                                            .findings_by_severity
                                                                            .medium
                                                                    }
                                                                    M
                                                                </Badge>
                                                            )}
                                                            {dev.findings_by_severity
                                                                .low > 0 && (
                                                                <Badge className="h-4 bg-blue-100 px-1 py-0 text-[10px] text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">
                                                                    {
                                                                        dev
                                                                            .findings_by_severity
                                                                            .low
                                                                    }
                                                                    L
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </td>

                                                    {/* Seniority */}
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${seniorityBadgeClass(dev.seniority_level)}`}
                                                        >
                                                            {dev.seniority_level}
                                                        </span>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
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
