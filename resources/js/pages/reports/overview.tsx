import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bug,
    CalendarDays,
    CheckCircle,
    Clock,
    Eye,
    Flame,
    GitBranch,
    GitCommitHorizontal,
    GitMerge,
    GitPullRequest,
    LayoutDashboard,
    Link2,
    Server,
    Timer,
    Users,
    XCircle,
} from 'lucide-react';
import type { ElementType } from 'react';

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

type Props = {
    stats: Stats;
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
                <p className="text-sm font-medium text-muted-foreground">{label}</p>
                <Icon className={`size-4 ${iconColor ?? 'text-muted-foreground'}`} />
            </div>
            <p className="mt-2 text-3xl font-bold tabular-nums">{value}</p>
        </div>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsOverview({ stats }: Props) {
    const showBanner = stats.critical_findings > 0 || stats.high_risk_prs > 0;

    return (
        <>
            <Head title="Reports — Overview" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Engineering Overview</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        System-wide health snapshot across all repositories and developers
                    </p>
                </div>

                <ReportsNav active="/reports" />

                {showBanner && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-400">
                        <strong>Attention needed:</strong>{' '}
                        {stats.critical_findings} critical finding{stats.critical_findings !== 1 ? 's' : ''} and{' '}
                        {stats.high_risk_prs} high-risk PR{stats.high_risk_prs !== 1 ? 's' : ''} require review.
                    </div>
                )}

                {/* Section 1 — Pull Request Activity */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        Pull Request Activity
                    </h2>
                    <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Total PRs"
                            value={stats.total_prs.toLocaleString()}
                            icon={GitPullRequest}
                        />
                        <StatCard
                            label="Open PRs"
                            value={stats.open_prs.toLocaleString()}
                            icon={Clock}
                            iconColor={stats.open_prs > 5 ? 'text-yellow-500' : undefined}
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
                            iconColor={stats.high_risk_prs > 0 ? 'text-red-500' : undefined}
                        />
                    </div>
                </div>

                {/* Section 2 — Code Quality */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        Code Quality
                    </h2>
                    <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Total Findings"
                            value={stats.total_findings.toLocaleString()}
                            icon={Bug}
                        />
                        <StatCard
                            label="Critical Findings"
                            value={stats.critical_findings.toLocaleString()}
                            icon={Flame}
                            iconColor={stats.critical_findings > 0 ? 'text-red-500' : undefined}
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
                            iconColor={stats.request_changes_reviews > 0 ? 'text-orange-500' : undefined}
                        />
                    </div>
                </div>

                {/* Section 3 — System Health */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        System Health
                    </h2>
                    <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
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
