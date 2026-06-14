import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Activity,
    CalendarDays,
    GitBranch,
    GitCommitHorizontal,
    LayoutDashboard,
    Users,
} from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';

// ─── Types ────────────────────────────────────────────────────────────────────

type CommitStat = {
    author_login: string;
    author_name: string | null;
    author_avatar_url: string | null;
    total_commits: number;
    low_effort_count: number;
    low_effort_pct: number;
    low_effort_messages: string[];
    total_additions: number;
    total_deletions: number;
};

type Props = {
    commits: CommitStat[];
    period: string;
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function pctColor(pct: number): string {
    if (pct > 50) return 'text-red-600 dark:text-red-400';
    if (pct >= 25) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-green-600 dark:text-green-400';
}

function commitStatus(pct: number) {
    if (pct === 0) {
        return <span className="text-xs font-medium text-green-700 dark:text-green-400">✓ Clean</span>;
    }
    if (pct <= 25) {
        return <span className="text-xs font-medium text-yellow-700 dark:text-yellow-400">~ Acceptable</span>;
    }
    return <span className="text-xs font-medium text-red-700 dark:text-red-400">✗ Needs improvement</span>;
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

// ─── Message chips ────────────────────────────────────────────────────────────

function MessageChips({ messages }: { messages: string[] }) {
    const [expanded, setExpanded] = useState(false);
    const visible = expanded ? messages : messages.slice(0, 3);
    const hasMore = messages.length > 3;

    if (messages.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {visible.map((msg, i) => (
                <code
                    key={i}
                    className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                    title={msg}
                >
                    {msg.length > 20 ? msg.slice(0, 20) + '…' : msg}
                </code>
            ))}
            {hasMore && !expanded && (
                <button
                    onClick={() => setExpanded(true)}
                    className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground hover:text-foreground"
                >
                    +{messages.length - 3} more
                </button>
            )}
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsCommits({ commits, period }: Props) {
    function handlePeriodChange(p: string) {
        router.get('/reports/commits', { period: p }, { preserveState: false });
    }

    const hasHighLowEffort = commits.some((c) => c.low_effort_pct > 50);

    return (
        <>
            <Head title="Reports — Commits" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Commit Quality</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Low-effort commit detection — who's writing meaningful commit messages
                    </p>
                </div>

                <ReportsNav active="/reports/commits" />

                <PeriodTabs current={period} onChange={handlePeriodChange} />

                {hasHighLowEffort && (
                    <div className="rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-400">
                        Some team members have more than half their commits flagged as low-effort. Consider a commit message convention.
                    </div>
                )}

                {commits.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <p className="text-sm">
                            No commit data for this period.
                        </p>
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                            <th className="px-4 py-3">Developer</th>
                                            <th className="px-4 py-3">Status</th>
                                            <th className="px-4 py-3 text-right">Total Commits</th>
                                            <th className="px-4 py-3">Low-Effort Commits</th>
                                            <th className="px-4 py-3">Example Messages</th>
                                            <th className="px-4 py-3">Code Volume</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {commits.map((dev) => {
                                            const initials = (
                                                dev.author_name ??
                                                dev.author_login
                                            )
                                                .slice(0, 2)
                                                .toUpperCase();

                                            const isHighLowEffort = dev.low_effort_pct > 50;

                                            return (
                                                <tr
                                                    key={dev.author_login}
                                                    className={[
                                                        'hover:bg-muted/40',
                                                        isHighLowEffort
                                                            ? 'bg-red-50/40 dark:bg-red-950/10'
                                                            : '',
                                                    ].join(' ')}
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

                                                    {/* Status */}
                                                    <td className="px-4 py-3">
                                                        {commitStatus(dev.low_effort_pct)}
                                                    </td>

                                                    {/* Total commits */}
                                                    <td className="px-4 py-3 text-right tabular-nums font-medium">
                                                        {dev.total_commits.toLocaleString()}
                                                    </td>

                                                    {/* Low-effort count + % */}
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {dev.low_effort_count === 0 ? (
                                                            <span className="text-muted-foreground">—</span>
                                                        ) : (
                                                            <>
                                                                <span className="font-medium">
                                                                    {dev.low_effort_count}
                                                                </span>
                                                                <span
                                                                    className={`ml-1.5 text-xs font-semibold ${pctColor(dev.low_effort_pct)}`}
                                                                >
                                                                    {dev.low_effort_pct}%
                                                                </span>
                                                            </>
                                                        )}
                                                    </td>

                                                    {/* Example bad messages */}
                                                    <td className="px-4 py-3">
                                                        <MessageChips
                                                            messages={dev.low_effort_messages}
                                                        />
                                                    </td>

                                                    {/* Code volume */}
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {dev.total_additions === 0 && dev.total_deletions === 0 ? (
                                                            <span className="text-muted-foreground">—</span>
                                                        ) : (
                                                            <>
                                                                <span className="text-green-600 dark:text-green-400">
                                                                    +{dev.total_additions.toLocaleString()}
                                                                </span>{' '}
                                                                <span className="text-red-600 dark:text-red-400">
                                                                    −{dev.total_deletions.toLocaleString()}
                                                                </span>
                                                            </>
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
                )}
            </div>
        </>
    );
}

ReportsCommits.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Commits', href: '/reports/commits' },
    ],
};
