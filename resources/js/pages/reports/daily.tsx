import { Head, router } from '@inertiajs/react';
import { ReportsNav } from '@/components/reports-nav';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

// ─── Types ────────────────────────────────────────────────────────────────────

type DailyStats = {
    date: string;
    prs_opened: number;
    prs_merged: number;
    commits: number;
    additions: number;
    deletions: number;
    findings: number;
    critical_findings: number;
    reviews: number;
};

type Author = {
    author_login: string;
    author_name: string | null;
};

type Props = {
    days: DailyStats[];
    period: string;
    author: string | null;
    authors: Author[];
};

// ─── Period filter ────────────────────────────────────────────────────────────

const periodOptions = [
    { label: '7 days', value: '7d' },
    { label: '30 days', value: '30d' },
    { label: '90 days', value: '90d' },
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

// ─── Helpers ─────────────────────────────────────────────────────────────────

function sumField(days: DailyStats[], key: keyof DailyStats): number {
    return days.reduce((acc, d) => acc + (d[key] as number), 0);
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsDaily({ days, period, author, authors }: Props) {
    function handlePeriodChange(p: string) {
        router.get(
            '/reports/daily',
            { period: p, author: author ?? undefined },
            { preserveState: false },
        );
    }

    function handleAuthorChange(login: string) {
        router.get(
            '/reports/daily',
            { period, author: login || undefined },
            { preserveState: false },
        );
    }

    const totals = {
        prs_opened: sumField(days, 'prs_opened'),
        prs_merged: sumField(days, 'prs_merged'),
        commits: sumField(days, 'commits'),
        additions: sumField(days, 'additions'),
        deletions: sumField(days, 'deletions'),
        findings: sumField(days, 'findings'),
        critical_findings: sumField(days, 'critical_findings'),
        reviews: sumField(days, 'reviews'),
    };

    return (
        <>
            <Head title="Reports - Daily Activity" />

            <div className="flex flex-1 flex-col gap-4 p-4 md:gap-6 md:p-6">
                <div>
                    <h1 className="text-2xl font-bold">Daily Activity</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        System-wide commit, PR, and review activity by day
                    </p>
                </div>

                <ReportsNav active="/reports/daily" />

                <div className="flex flex-wrap items-center gap-3">
                    <PeriodTabs
                        current={period}
                        onChange={handlePeriodChange}
                    />

                    {authors.length > 0 && (
                        <select
                            value={author ?? ''}
                            onChange={(e) => handleAuthorChange(e.target.value)}
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
                </div>

                {days.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <p className="text-sm">No activity in this period.</p>
                    </div>
                ) : (
                    <>
                        {/* Period totals mini cards */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div className="rounded-lg border bg-card p-3 shadow-sm">
                                <p className="text-xs text-muted-foreground">
                                    PRs Opened
                                </p>
                                <p className="mt-1 text-xl font-bold tabular-nums">
                                    {totals.prs_opened}
                                </p>
                            </div>
                            <div className="rounded-lg border bg-card p-3 shadow-sm">
                                <p className="text-xs text-muted-foreground">
                                    Commits
                                </p>
                                <p className="mt-1 text-xl font-bold tabular-nums">
                                    {totals.commits.toLocaleString()}
                                </p>
                            </div>
                            <div className="rounded-lg border bg-card p-3 shadow-sm">
                                <p className="text-xs text-muted-foreground">
                                    Findings
                                </p>
                                <p className="mt-1 text-xl font-bold tabular-nums">
                                    <span
                                        className={
                                            totals.critical_findings > 0
                                                ? 'text-red-600 dark:text-red-400'
                                                : ''
                                        }
                                    >
                                        {totals.findings}
                                    </span>
                                    {totals.critical_findings > 0 && (
                                        <span className="ml-1.5 text-sm font-medium text-red-500">
                                            ({totals.critical_findings}C)
                                        </span>
                                    )}
                                </p>
                            </div>
                            <div className="rounded-lg border bg-card p-3 shadow-sm">
                                <p className="text-xs text-muted-foreground">
                                    Reviews
                                </p>
                                <p className="mt-1 text-xl font-bold tabular-nums">
                                    {totals.reviews}
                                </p>
                            </div>
                        </div>

                        <Card>
                            <CardContent className="p-0">
                                <DataTable>
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                            <th className="px-4 py-3">Date</th>
                                            <th className="px-4 py-3 text-right">
                                                PRs Opened
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                PRs Merged
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Commits
                                            </th>
                                            <th className="px-4 py-3">
                                                <TooltipProvider>
                                                    <Tooltip>
                                                        <TooltipTrigger className="cursor-help underline decoration-dotted">
                                                            Code (+/−)
                                                        </TooltipTrigger>
                                                        <TooltipContent className="max-w-48 text-center">
                                                            Total lines added
                                                            and removed across
                                                            all commits on this
                                                            day.
                                                        </TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                <TooltipProvider>
                                                    <Tooltip>
                                                        <TooltipTrigger className="cursor-help underline decoration-dotted">
                                                            Findings
                                                        </TooltipTrigger>
                                                        <TooltipContent className="max-w-48 text-center">
                                                            Code issues flagged
                                                            by AI reviews
                                                            completed on this
                                                            day.
                                                        </TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                <TooltipProvider>
                                                    <Tooltip>
                                                        <TooltipTrigger className="cursor-help underline decoration-dotted">
                                                            Reviews
                                                        </TooltipTrigger>
                                                        <TooltipContent className="max-w-44 text-center">
                                                            AI reviews completed
                                                            on this day.
                                                        </TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {/* Daily rows */}
                                        {days.map((day) => (
                                            <tr
                                                key={day.date}
                                                className="hover:bg-muted/40"
                                            >
                                                {/* Date */}
                                                <td className="px-4 py-3 font-medium tabular-nums">
                                                    {day.date}
                                                </td>

                                                {/* PRs Opened */}
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {day.prs_opened > 0 ? (
                                                        <span className="font-medium">
                                                            {day.prs_opened}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>

                                                {/* PRs Merged */}
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {day.prs_merged > 0 ? (
                                                        <span className="font-medium text-purple-600 dark:text-purple-400">
                                                            {day.prs_merged}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Commits */}
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {day.commits > 0 ? (
                                                        <span>
                                                            {day.commits.toLocaleString()}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Code (+/−) */}
                                                <td className="px-4 py-3 tabular-nums">
                                                    {day.additions > 0 ||
                                                    day.deletions > 0 ? (
                                                        <>
                                                            <span className="text-green-600 dark:text-green-400">
                                                                +
                                                                {day.additions.toLocaleString()}
                                                            </span>{' '}
                                                            <span className="text-red-600 dark:text-red-400">
                                                                −
                                                                {day.deletions.toLocaleString()}
                                                            </span>
                                                        </>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Findings */}
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {day.findings > 0 ? (
                                                        <div className="flex items-center justify-end gap-1">
                                                            <span
                                                                className={
                                                                    day.critical_findings >
                                                                    0
                                                                        ? 'font-medium text-amber-600 dark:text-amber-400'
                                                                        : ''
                                                                }
                                                            >
                                                                {day.findings}
                                                            </span>
                                                            {day.critical_findings >
                                                                0 && (
                                                                <span className="inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                                                    {
                                                                        day.critical_findings
                                                                    }
                                                                    C
                                                                </span>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Reviews */}
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {day.reviews > 0 ? (
                                                        <span>
                                                            {day.reviews}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </DataTable>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}

ReportsDaily.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Daily Activity', href: '/reports/daily' },
    ],
};
