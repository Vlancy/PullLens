import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    Activity,
    CalendarDays,
    CheckCircle,
    GitBranch,
    GitCommitHorizontal,
    LayoutDashboard,
    Users,
    XCircle,
} from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

// ─── Types ────────────────────────────────────────────────────────────────────

type DailyRow = {
    date: string;
    author_login: string;
    author_name: string | null;
    author_avatar_url: string | null;
    total_commits: number;
    low_effort_commits: number;
    useful_commits: number;
    additions: number;
    deletions: number;
    active_hours: number;
    prs_opened: number;
    is_productive: boolean;
};

type Repo = { id: string; name: string; full_name: string };

type Props = {
    rows: DailyRow[];
    period: string;
    repo_id: string | null;
    repositories: Repo[];
};

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

function formatDate(dateStr: string): string {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-US', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

function formatActiveWindow(activeHours: number, totalCommits: number): string {
    if (activeHours === 0 && totalCommits === 1) return 'single commit';
    if (activeHours < 0.1) return '< 1 min';
    if (activeHours < 1) return `${Math.round(activeHours * 60)}m`;
    return `${activeHours}h`;
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsDeveloperDaily({ rows, period, repo_id, repositories }: Props) {
    const [search, setSearch] = useState('');

    function handlePeriodChange(p: string) {
        router.get('/reports/developer-daily', { period: p, repo_id: repo_id ?? undefined }, { preserveState: false });
    }

    function handleRepoChange(id: string) {
        router.get('/reports/developer-daily', { period, repo_id: id || undefined }, { preserveState: false });
    }

    const filtered = useMemo(() => {
        if (!search.trim()) return rows;
        const q = search.toLowerCase();
        return rows.filter(
            (r) =>
                r.author_login.toLowerCase().includes(q) ||
                (r.author_name ?? '').toLowerCase().includes(q),
        );
    }, [rows, search]);

    // Group rows by date for sticky date headers
    type Group = { date: string; rows: DailyRow[] };
    const grouped = useMemo<Group[]>(() => {
        const map = new Map<string, DailyRow[]>();
        for (const row of filtered) {
            const existing = map.get(row.date);
            if (existing) {
                existing.push(row);
            } else {
                map.set(row.date, [row]);
            }
        }
        return Array.from(map.entries()).map(([date, rows]) => ({ date, rows }));
    }, [filtered]);

    return (
        <>
            <Head title="Reports — Developer Daily" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Daily Effort</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        What each developer worked on — day by day
                    </p>
                </div>

                <ReportsNav active="/reports/developer-daily" />

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

                {filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <p className="text-sm">No commit activity for this period.</p>
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                            <th className="px-4 py-3">Date</th>
                                            <th className="px-4 py-3">Developer</th>
                                            <th className="px-4 py-3">Commits</th>
                                            <th className="px-4 py-3">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Code</TooltipTrigger>
                                                    <TooltipContent className="max-w-48 text-center">Lines added and removed in commits on this day.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3">
                                                <TooltipProvider>
                                                    <Tooltip>
                                                        <TooltipTrigger className="underline decoration-dotted cursor-help">
                                                            Active window
                                                        </TooltipTrigger>
                                                        <TooltipContent className="max-w-56 text-center">
                                                            Time between first and last commit of the day. Shows session length, not total hours worked.
                                                        </TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">PRs</TooltipTrigger>
                                                    <TooltipContent className="max-w-48 text-center">Pull requests opened or contributed to on this day.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3 text-center">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Productive</TooltipTrigger>
                                                    <TooltipContent className="max-w-52 text-center">Marked productive if ≥2 commits or ≥1 merged PR on this day.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {grouped.map((group) => (
                                            <>
                                                {/* Date header row */}
                                                <tr key={`date-${group.date}`}>
                                                    <td
                                                        colSpan={7}
                                                        className="bg-muted/60 px-4 py-2"
                                                    >
                                                        <span className="text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                                            {formatDate(group.date)}
                                                        </span>
                                                    </td>
                                                </tr>

                                                {/* Developer rows for this date */}
                                                {group.rows.map((row) => {
                                                    const initials = (
                                                        row.author_name ?? row.author_login
                                                    )
                                                        .slice(0, 2)
                                                        .toUpperCase();

                                                    return (
                                                        <tr
                                                            key={`${row.date}|${row.author_login}`}
                                                            className={[
                                                                'hover:bg-muted/40',
                                                                !row.is_productive && row.total_commits > 0
                                                                    ? 'bg-red-50/30 dark:bg-red-950/10'
                                                                    : '',
                                                            ].join(' ')}
                                                        >
                                                            {/* Date (empty — covered by group header) */}
                                                            <td className="px-4 py-3" />

                                                            {/* Developer */}
                                                            <td className="px-4 py-3">
                                                                <div className="flex items-center gap-2.5">
                                                                    <Avatar className="size-7 shrink-0">
                                                                        <AvatarImage
                                                                            src={row.author_avatar_url ?? undefined}
                                                                        />
                                                                        <AvatarFallback className="text-xs">
                                                                            {initials}
                                                                        </AvatarFallback>
                                                                    </Avatar>
                                                                    <div className="min-w-0">
                                                                        <p className="truncate font-medium leading-snug">
                                                                            {row.author_name ?? row.author_login}
                                                                        </p>
                                                                        {row.author_name && (
                                                                            <p className="truncate text-xs text-muted-foreground">
                                                                                @{row.author_login}
                                                                            </p>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </td>

                                                            {/* Commits */}
                                                            <td className="px-4 py-3 tabular-nums">
                                                                <span className="font-medium">
                                                                    {row.useful_commits}
                                                                </span>
                                                                {row.low_effort_commits > 0 && (
                                                                    <span className="ml-1.5 text-xs text-red-500 dark:text-red-400">
                                                                        ⚠ {row.low_effort_commits} low-effort
                                                                    </span>
                                                                )}
                                                            </td>

                                                            {/* Code */}
                                                            <td className="px-4 py-3 tabular-nums">
                                                                {row.additions > 0 || row.deletions > 0 ? (
                                                                    <>
                                                                        <span className="text-green-600 dark:text-green-400">
                                                                            +{row.additions.toLocaleString()}
                                                                        </span>{' '}
                                                                        <span className="text-red-600 dark:text-red-400">
                                                                            −{row.deletions.toLocaleString()}
                                                                        </span>
                                                                    </>
                                                                ) : (
                                                                    <span className="text-muted-foreground">—</span>
                                                                )}
                                                            </td>

                                                            {/* Active window */}
                                                            <td className="px-4 py-3">
                                                                <div className="flex items-center gap-2">
                                                                    {row.active_hours >= 1 && (
                                                                        <div className="h-1.5 w-12 rounded-full bg-muted overflow-hidden">
                                                                            <div
                                                                                className="h-full rounded-full bg-blue-400"
                                                                                style={{
                                                                                    width: `${Math.min(100, (row.active_hours / 8) * 100)}%`,
                                                                                }}
                                                                            />
                                                                        </div>
                                                                    )}
                                                                    <span className="text-xs tabular-nums text-muted-foreground">
                                                                        {formatActiveWindow(
                                                                            row.active_hours,
                                                                            row.total_commits,
                                                                        )}
                                                                    </span>
                                                                </div>
                                                            </td>

                                                            {/* PRs */}
                                                            <td className="px-4 py-3 text-right tabular-nums">
                                                                {row.prs_opened > 0 ? (
                                                                    <span className="font-medium">{row.prs_opened}</span>
                                                                ) : (
                                                                    <span className="text-muted-foreground">—</span>
                                                                )}
                                                            </td>

                                                            {/* Productive */}
                                                            <td className="px-4 py-3 text-center">
                                                                {row.is_productive ? (
                                                                    <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                                                        <CheckCircle className="size-3" /> Active
                                                                    </span>
                                                                ) : (
                                                                    <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                                                        <XCircle className="size-3" /> Low effort
                                                                    </span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </>
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

ReportsDeveloperDaily.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Developer Daily', href: '/reports/developer-daily' },
    ],
};
