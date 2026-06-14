import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';

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

type Props = {
    days: DailyStats[];
    period: string;
};

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

export default function ReportsDaily({ days, period }: Props) {
    function handlePeriodChange(p: string) {
        router.get('/reports/daily', { period: p }, { preserveState: false });
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
            <Head title="Reports — Daily Activity" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold">Reports</h1>
                    <p className="text-sm text-muted-foreground">
                        Day-by-day activity breakdown
                    </p>
                </div>

                <ReportsNav active="/reports/daily" />

                <PeriodTabs current={period} onChange={handlePeriodChange} />

                {days.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <p className="text-sm">No activity in this period.</p>
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
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
                                                Code (+/−)
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Findings
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Reviews
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {/* Summary row */}
                                        <tr className="bg-muted/30 font-medium">
                                            <td className="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                Period total
                                            </td>
                                            <td className="px-4 py-2.5 text-right tabular-nums">
                                                {totals.prs_opened}
                                            </td>
                                            <td className="px-4 py-2.5 text-right tabular-nums text-purple-600 dark:text-purple-400">
                                                {totals.prs_merged}
                                            </td>
                                            <td className="px-4 py-2.5 text-right tabular-nums">
                                                {totals.commits.toLocaleString()}
                                            </td>
                                            <td className="px-4 py-2.5 tabular-nums">
                                                <span className="text-green-600 dark:text-green-400">
                                                    +{totals.additions.toLocaleString()}
                                                </span>{' '}
                                                <span className="text-red-600 dark:text-red-400">
                                                    −{totals.deletions.toLocaleString()}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5 text-right tabular-nums">
                                                <span
                                                    className={
                                                        totals.critical_findings > 0
                                                            ? 'text-amber-600 dark:text-amber-400'
                                                            : ''
                                                    }
                                                >
                                                    {totals.findings}
                                                </span>
                                                {totals.critical_findings > 0 && (
                                                    <span className="ml-1 inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                                        {totals.critical_findings}C
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-right tabular-nums">
                                                {totals.reviews}
                                            </td>
                                        </tr>

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
                                                            —
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
                                                            —
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
                                                            —
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Code (+/−) */}
                                                <td className="px-4 py-3 tabular-nums">
                                                    {day.additions > 0 ||
                                                    day.deletions > 0 ? (
                                                        <>
                                                            <span className="text-green-600 dark:text-green-400">
                                                                +{day.additions.toLocaleString()}
                                                            </span>{' '}
                                                            <span className="text-red-600 dark:text-red-400">
                                                                −{day.deletions.toLocaleString()}
                                                            </span>
                                                        </>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
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
                                                            —
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Reviews */}
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {day.reviews > 0 ? (
                                                        <span>{day.reviews}</span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
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

ReportsDaily.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Daily Activity', href: '/reports/daily' },
    ],
};
