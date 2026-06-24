import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    Activity,
    ArrowLeft,
    CalendarDays,
    GitBranch,
    GitCommitHorizontal,
    GitMerge,
    GitPullRequest,
    LayoutDashboard,
    Users,
    Zap,
} from 'lucide-react';
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
    resolved_findings_count: number;
    resolution_rate: number | null;
    findings_by_severity: { critical: number; high: number; medium: number; low: number; false_positive: number };
    seniority_score: number | null;
    seniority_level: 'Junior' | 'Mid' | 'Senior' | 'Expert' | null;
    avg_estimated_hours: number | null;
    avg_time_to_first_review_hours: number | null;
};

type CalendarDay = { date: string; commits: number; additions: number; deletions: number };
type WeeklyPR    = { week: string; opened: number; merged: number };
type WeeklyFinding = { week: string; count: number; high_risk: number };
type DowEntry    = { day: number; commits: number };
type RecentPR    = { number: number; title: string; web_url: string | null; state: string; opened_at: string; merged_at: string | null; merge_hours: number | null; findings_count: number };

type Profile = {
    calendar: CalendarDay[];
    weekly_prs: WeeklyPR[];
    weekly_findings: WeeklyFinding[];
    pr_sizes: { xs: number; sm: number; md: number; lg: number; xl: number };
    dow_pattern: DowEntry[];
    recent_prs: RecentPR[];
    active_days: number;
    current_streak: number;
    longest_streak: number;
};

type Repo = { id: string; name: string; full_name: string };

type Props = {
    login: string;
    developer: Developer | null;
    profile: Profile;
    repo_id: string | null;
    repositories: Repo[];
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

const DOW_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MONTH_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function formatHours(h: number | string | null): string {
    if (h === null || h === undefined) return '—';
    const n = Number(h);
    if (isNaN(n)) return '—';
    if (n < 1) return `${Math.round(n * 60)}m`;
    if (n < 24) return `${n.toFixed(1)}h`;
    return `${(n / 24).toFixed(1)}d`;
}

function calColor(commits: number): string {
    if (commits === 0) return 'bg-muted';
    if (commits <= 2)  return 'bg-primary/25';
    if (commits <= 5)  return 'bg-primary/50';
    if (commits <= 10) return 'bg-primary/75';
    return 'bg-primary';
}

function seniorityBadgeClass(level: string | null): string {
    switch (level) {
        case 'Expert': return 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300';
        case 'Senior': return 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300';
        case 'Mid':    return 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300';
        case 'Junior': return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300';
        default:       return 'bg-muted text-muted-foreground';
    }
}

// ─── Sub-nav ──────────────────────────────────────────────────────────────────

function ReportsNav() {
    const tabs = [
        { icon: LayoutDashboard,       label: 'Overview',       href: '/reports' },
        { icon: Users,                 label: 'Team',           href: '/reports/developers' },
        { icon: GitBranch,             label: 'Repos',          href: '/reports/repositories' },
        { icon: GitCommitHorizontal,   label: 'Commit Quality', href: '/reports/commits' },
        { icon: CalendarDays,          label: 'Daily Activity', href: '/reports/daily' },
        { icon: Activity,              label: 'Daily Effort',   href: '/reports/developer-daily' },
    ];

    return (
        <div className="flex gap-0.5 border-b border-border">
            {tabs.map((tab) => (
                <Link
                    key={tab.href}
                    href={tab.href}
                    className="flex items-center gap-1.5 px-3 py-2 text-sm font-medium transition-colors rounded-t-md text-muted-foreground hover:text-foreground hover:bg-muted/50"
                >
                    <tab.icon className="size-3.5 shrink-0" />
                    {tab.label}
                </Link>
            ))}
        </div>
    );
}

// ─── Contribution Calendar ────────────────────────────────────────────────────

function ContributionCalendar({ calendar }: { calendar: CalendarDay[] }) {
    const { weeks, monthLabels } = useMemo(() => {
        const firstDate = new Date(calendar[0].date + 'T00:00:00');
        const startDow  = firstDate.getDay();

        const cells: (CalendarDay | null)[] = [...Array(startDow).fill(null), ...calendar];
        while (cells.length % 7 !== 0) cells.push(null);

        const ws: (CalendarDay | null)[][] = [];
        for (let i = 0; i < cells.length; i += 7) ws.push(cells.slice(i, i + 7));

        const labels: { label: string; col: number }[] = [];
        let lastMonth = -1;
        ws.forEach((week, col) => {
            const first = week.find(d => d !== null);
            if (first) {
                const m = new Date(first.date + 'T00:00:00').getMonth();
                if (m !== lastMonth) {
                    labels.push({ label: MONTH_SHORT[m], col });
                    lastMonth = m;
                }
            }
        });

        return { weeks: ws, monthLabels: labels };
    }, [calendar]);

    return (
        <div className="overflow-x-auto">
            {/* Month labels */}
            <div className="flex mb-1" style={{ paddingLeft: '16px' }}>
                {weeks.map((_, col) => {
                    const lbl = monthLabels.find(l => l.col === col);
                    return (
                        <div key={col} className="flex-none" style={{ width: '14px', marginRight: '2px' }}>
                            {lbl && <span className="text-[9px] text-muted-foreground">{lbl.label}</span>}
                        </div>
                    );
                })}
            </div>
            <div className="flex gap-0 items-start">
                {/* Day-of-week labels */}
                <div className="flex flex-col gap-[2px] mr-1 mt-[1px]">
                    {DOW_LABELS.map((d, i) => (
                        <div key={i} className="h-[12px] text-[9px] leading-[12px] text-muted-foreground text-right pr-1">
                            {i % 2 === 1 ? d : ''}
                        </div>
                    ))}
                </div>
                {/* Grid */}
                <div className="flex gap-[2px]">
                    {weeks.map((week, wi) => (
                        <div key={wi} className="flex flex-col gap-[2px]">
                            {week.map((day, di) => (
                                <div
                                    key={di}
                                    className={`size-3 rounded-[2px] ${day ? calColor(day.commits) : 'bg-transparent'}`}
                                    title={day ? `${day.date}: ${day.commits} commits, +${day.additions}/−${day.deletions}` : undefined}
                                />
                            ))}
                        </div>
                    ))}
                </div>
            </div>
            {/* Legend */}
            <div className="mt-2 flex items-center gap-1 justify-end text-[10px] text-muted-foreground">
                <span>Less</span>
                {['bg-muted', 'bg-primary/25', 'bg-primary/50', 'bg-primary/75', 'bg-primary'].map(c => (
                    <div key={c} className={`size-3 rounded-[2px] ${c}`} />
                ))}
                <span>More</span>
            </div>
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function DeveloperProfile({ login, developer, profile, repo_id, repositories }: Props) {
    const displayName = developer?.author_name ?? login;
    const initials    = displayName.slice(0, 2).toUpperCase();

    const maxPR  = Math.max(...profile.weekly_prs.map(w => Math.max(w.opened, w.merged)), 1);
    const maxFnd = Math.max(...profile.weekly_findings.map(w => w.count), 1);
    const maxDow = Math.max(...profile.dow_pattern.map(d => d.commits), 1);
    const maxSize = Math.max(...Object.values(profile.pr_sizes), 1);

    return (
        <>
            <Head title={`Developer — ${displayName}`} />

            <div className="flex flex-1 flex-col gap-6 p-6">
                {/* Back + nav */}
                <div>
                    <Link href="/reports/developers" className="mb-3 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-3.5" /> Back to Team
                    </Link>
                    <ReportsNav />
                </div>

                {/* Repo filter */}
                {repositories.length > 1 && (
                    <div className="flex items-center gap-2">
                        <select
                            value={repo_id ?? ''}
                            onChange={e => router.get(`/reports/developers/${login}`, { repo_id: e.target.value || undefined }, { preserveState: false })}
                            className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground"
                        >
                            <option value="">All repositories</option>
                            {repositories.map(r => (
                                <option key={r.id} value={r.id}>{r.full_name}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* Header */}
                <Card>
                    <CardContent className="p-5">
                        <div className="flex flex-wrap items-start gap-4">
                            <Avatar className="size-16 shrink-0">
                                <AvatarImage src={developer?.author_avatar_url ?? undefined} />
                                <AvatarFallback className="text-lg">{initials}</AvatarFallback>
                            </Avatar>
                            <div className="flex-1 min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-xl font-bold">{displayName}</h1>
                                    {developer?.author_name && (
                                        <span className="text-sm text-muted-foreground">@{login}</span>
                                    )}
                                    {developer?.seniority_level && (
                                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${seniorityBadgeClass(developer.seniority_level)}`}>
                                            {developer.seniority_level}
                                        </span>
                                    )}
                                </div>
                                {/* Quick stats row */}
                                <div className="mt-3 flex flex-wrap gap-6">
                                    <div className="text-center">
                                        <p className="text-xl font-bold tabular-nums">{developer?.total_prs ?? 0}</p>
                                        <p className="text-xs text-muted-foreground">Total PRs</p>
                                    </div>
                                    <div className="text-center">
                                        <p className="text-xl font-bold tabular-nums text-purple-600 dark:text-purple-400">{developer?.merged_prs ?? 0}</p>
                                        <p className="text-xs text-muted-foreground">Merged</p>
                                    </div>
                                    <div className="text-center">
                                        <p className="text-xl font-bold tabular-nums">{developer?.total_commits ?? 0}</p>
                                        <p className="text-xs text-muted-foreground">Commits</p>
                                    </div>
                                    <div className="text-center">
                                        <p className="text-xl font-bold tabular-nums text-green-600 dark:text-green-400">+{(developer?.total_additions ?? 0).toLocaleString()}</p>
                                        <p className="text-xs text-muted-foreground">Lines added</p>
                                    </div>
                                    <div className="text-center">
                                        <p className="text-xl font-bold tabular-nums">{formatHours(developer?.avg_estimated_hours ?? null)}</p>
                                        <p className="text-xs text-muted-foreground">Avg effort/PR</p>
                                    </div>
                                    <div className="text-center">
                                        <p className="text-xl font-bold tabular-nums">{profile.active_days}</p>
                                        <p className="text-xs text-muted-foreground">Active days</p>
                                    </div>
                                </div>
                            </div>
                            {/* Streak banner */}
                            <div className="flex flex-col items-center gap-1 rounded-lg bg-muted/50 px-5 py-3 text-center">
                                <Zap className="size-4 text-amber-500" />
                                <p className="text-lg font-bold tabular-nums">{profile.current_streak}</p>
                                <p className="text-xs text-muted-foreground">day streak</p>
                                <p className="mt-1 text-[10px] text-muted-foreground">Best: {profile.longest_streak}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Contribution calendar */}
                <Card>
                    <CardContent className="p-4">
                        <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Contribution Calendar — last 52 weeks</p>
                        {profile.calendar.length > 0 && <ContributionCalendar calendar={profile.calendar} />}
                    </CardContent>
                </Card>

                {/* Weekly trend charts + day-of-week */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    {/* Weekly PR trend */}
                    <Card>
                        <CardContent className="p-4">
                            <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Weekly PRs (12 weeks)</p>
                            <div className="flex items-end gap-1 h-24">
                                {profile.weekly_prs.map((w, i) => (
                                    <div key={i} className="flex flex-1 flex-col items-center gap-0.5">
                                        <div className="flex w-full items-end gap-0.5 justify-center" style={{ height: '80px' }}>
                                            <div
                                                className="flex-1 rounded-sm bg-primary/30"
                                                style={{ height: `${Math.max((w.opened / maxPR) * 80, w.opened > 0 ? 3 : 0)}px` }}
                                                title={`Wk ${i + 1} opened: ${w.opened}`}
                                            />
                                            <div
                                                className="flex-1 rounded-sm bg-primary"
                                                style={{ height: `${Math.max((w.merged / maxPR) * 80, w.merged > 0 ? 3 : 0)}px` }}
                                                title={`Wk ${i + 1} merged: ${w.merged}`}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-2 flex gap-3 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1"><span className="inline-block size-2 rounded-sm bg-primary/30" /> Opened</span>
                                <span className="flex items-center gap-1"><span className="inline-block size-2 rounded-sm bg-primary" /> Merged</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Weekly findings trend */}
                    <Card>
                        <CardContent className="p-4">
                            <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Weekly Findings (12 weeks)</p>
                            <div className="flex items-end gap-1 h-24">
                                {profile.weekly_findings.map((w, i) => (
                                    <div key={i} className="flex flex-1 flex-col items-center gap-0.5">
                                        <div className="flex w-full items-end gap-0.5 justify-center" style={{ height: '80px' }}>
                                            <div
                                                className="w-full rounded-sm bg-amber-400/50"
                                                style={{ height: `${Math.max((w.count / maxFnd) * 80, w.count > 0 ? 3 : 0)}px` }}
                                                title={`Total: ${w.count}`}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-2 flex gap-3 text-xs text-muted-foreground">
                                <span className="flex items-center gap-1"><span className="inline-block size-2 rounded-sm bg-amber-400/50" /> Findings</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Day-of-week pattern */}
                    <Card>
                        <CardContent className="p-4">
                            <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Day-of-Week Commits</p>
                            <div className="flex items-end gap-2 h-24">
                                {profile.dow_pattern.map((d) => (
                                    <div key={d.day} className="flex flex-1 flex-col items-center gap-1">
                                        <div style={{ height: '80px', display: 'flex', alignItems: 'flex-end' }}>
                                            <div
                                                className="w-full rounded-sm bg-sky-500/60"
                                                style={{ height: `${Math.max((d.commits / maxDow) * 80, d.commits > 0 ? 3 : 0)}px` }}
                                                title={`${DOW_LABELS[d.day]}: ${d.commits} commits`}
                                            />
                                        </div>
                                        <span className="text-[9px] text-muted-foreground">{DOW_LABELS[d.day].slice(0, 1)}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* PR size distribution + findings breakdown */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {/* PR size distribution */}
                    <Card>
                        <CardContent className="p-4">
                            <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">PR Size Distribution</p>
                            <div className="flex flex-col gap-2">
                                {(['xs', 'sm', 'md', 'lg', 'xl'] as const).map(size => {
                                    const labels = { xs: 'XS (<10)', sm: 'S (<50)', md: 'M (<200)', lg: 'L (<500)', xl: 'XL (500+)' };
                                    const val = profile.pr_sizes[size];
                                    return (
                                        <div key={size} className="flex items-center gap-2">
                                            <span className="w-16 shrink-0 text-xs text-muted-foreground">{labels[size]}</span>
                                            <div className="flex-1 overflow-hidden rounded-full bg-muted h-2">
                                                <div
                                                    className="h-full rounded-full bg-primary/60"
                                                    style={{ width: `${(val / maxSize) * 100}%` }}
                                                />
                                            </div>
                                            <span className="w-6 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{val}</span>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Findings breakdown */}
                    <Card>
                        <CardContent className="p-4">
                            <p className="mb-3 text-xs font-medium uppercase tracking-wide text-muted-foreground">Findings Breakdown</p>
                            {developer && developer.total_findings > 0 ? (
                                <div className="flex flex-col gap-3">
                                    {(['critical', 'high', 'medium', 'low'] as const).map(sev => {
                                        const count = developer.findings_by_severity[sev];
                                        const colors = {
                                            critical: 'bg-red-500',
                                            high:     'bg-orange-400',
                                            medium:   'bg-yellow-400',
                                            low:      'bg-blue-400',
                                        };
                                        return (
                                            <div key={sev} className="flex items-center gap-2">
                                                <span className="w-16 shrink-0 capitalize text-xs text-muted-foreground">{sev}</span>
                                                <div className="flex-1 overflow-hidden rounded-full bg-muted h-2">
                                                    <div
                                                        className={`h-full rounded-full ${colors[sev]}`}
                                                        style={{ width: `${(count / developer.total_findings) * 100}%` }}
                                                    />
                                                </div>
                                                <span className="w-6 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{count}</span>
                                            </div>
                                        );
                                    })}
                                    {developer.resolution_rate !== null && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Fix rate: <strong className="text-foreground">{developer.resolution_rate}%</strong>
                                            {' '}({developer.resolved_findings_count} resolved)
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-green-600 dark:text-green-400">No findings — clean record</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Recent PRs */}
                <Card>
                    <CardContent className="p-0">
                        <div className="px-4 pt-4 pb-2">
                            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Recent Pull Requests</p>
                        </div>
                        {profile.recent_prs.length === 0 ? (
                            <p className="px-4 pb-4 text-sm text-muted-foreground">No pull requests found.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                            <th className="px-4 py-2">Title</th>
                                            <th className="px-4 py-2">State</th>
                                            <th className="px-4 py-2">Opened</th>
                                            <th className="px-4 py-2">Merge time</th>
                                            <th className="px-4 py-2">Findings</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {profile.recent_prs.map((pr) => (
                                            <tr key={pr.number} className="hover:bg-muted/40">
                                                <td className="px-4 py-2.5 max-w-xs">
                                                    {pr.web_url ? (
                                                        <a href={pr.web_url} target="_blank" rel="noreferrer" className="truncate font-medium hover:underline flex items-center gap-1.5">
                                                            <GitPullRequest className="size-3.5 shrink-0 text-muted-foreground" />
                                                            <span className="truncate">{pr.title}</span>
                                                        </a>
                                                    ) : (
                                                        <span className="truncate font-medium">{pr.title}</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {pr.state === 'merged' && (
                                                        <span className="inline-flex items-center gap-1 text-xs font-medium text-purple-700 dark:text-purple-400">
                                                            <GitMerge className="size-3" /> Merged
                                                        </span>
                                                    )}
                                                    {pr.state === 'open' && (
                                                        <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400">
                                                            <GitPullRequest className="size-3" /> Open
                                                        </span>
                                                    )}
                                                    {pr.state === 'closed' && (
                                                        <span className="text-xs text-muted-foreground">Closed</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-2.5 text-xs tabular-nums text-muted-foreground">
                                                    {new Date(pr.opened_at).toLocaleDateString('en', { month: 'short', day: 'numeric', year: 'numeric' })}
                                                </td>
                                                <td className="px-4 py-2.5 text-xs tabular-nums text-muted-foreground">
                                                    {formatHours(pr.merge_hours)}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    {pr.findings_count > 0 ? (
                                                        <Badge className="h-4 bg-amber-100 px-1.5 py-0 text-[10px] text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
                                                            {pr.findings_count}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-xs text-green-600 dark:text-green-400">Clean</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

DeveloperProfile.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Developers', href: '/reports/developers' },
        { title: 'Profile', href: '#' },
    ],
};
