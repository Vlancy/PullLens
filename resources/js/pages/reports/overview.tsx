import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart2,
    BookOpen,
    CheckCircle,
    Clock,
    GitMerge,
    GitPullRequest,
    Link2,
    MessageSquare,
    ShieldAlert,
    TriangleAlert,
    XCircle,
} from 'lucide-react';
import type { ElementType } from 'react';
import { Card, CardContent } from '@/components/ui/card';

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
        { label: 'Overview', href: '/reports' },
        { label: 'Developers', href: '/reports/developers' },
        { label: 'Repositories', href: '/reports/repositories' },
        { label: 'Commits', href: '/reports/commits' },
        { label: 'Daily', href: '/reports/daily' },
        { label: 'Dev Daily', href: '/reports/developer-daily' },
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

// ─── Stat card ────────────────────────────────────────────────────────────────

function StatCard({
    label,
    value,
    icon: Icon,
    accent,
    iconAccent,
    formatValue,
}: {
    label: string;
    value: number;
    icon: ElementType;
    accent?: string;
    iconAccent?: string;
    formatValue?: (v: number) => string;
}) {
    const display = formatValue ? formatValue(value) : value.toLocaleString();

    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">{label}</p>
                        <p
                            className={`mt-1 text-3xl font-semibold tracking-tight tabular-nums ${accent ?? 'text-foreground'}`}
                        >
                            {display}
                        </p>
                    </div>
                    <div
                        className={`flex size-10 shrink-0 items-center justify-center rounded-lg ${iconAccent ?? 'bg-muted'}`}
                    >
                        <Icon
                            className={`size-5 ${iconAccent ? 'text-white' : 'text-muted-foreground'}`}
                        />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsOverview({ stats }: Props) {
    return (
        <>
            <Head title="Reports — Overview" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold">Reports</h1>
                    <p className="text-sm text-muted-foreground">
                        System-wide activity at a glance
                    </p>
                </div>

                <ReportsNav active="/reports" />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Total pull requests"
                        value={stats.total_prs}
                        icon={GitPullRequest}
                    />
                    <StatCard
                        label="Open pull requests"
                        value={stats.open_prs}
                        icon={BookOpen}
                        accent="text-green-600 dark:text-green-400"
                    />
                    <StatCard
                        label="Merged pull requests"
                        value={stats.merged_prs}
                        icon={GitMerge}
                        accent="text-purple-600 dark:text-purple-400"
                    />
                    <StatCard
                        label="Total reviews"
                        value={stats.total_reviews}
                        icon={MessageSquare}
                    />
                    <StatCard
                        label="Total findings"
                        value={stats.total_findings}
                        icon={TriangleAlert}
                        accent={
                            stats.critical_findings > 0
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-amber-600 dark:text-amber-400'
                        }
                    />
                    <StatCard
                        label="Critical findings"
                        value={stats.critical_findings}
                        icon={ShieldAlert}
                        accent={
                            stats.critical_findings > 0
                                ? 'text-red-600 dark:text-red-400'
                                : undefined
                        }
                        iconAccent={
                            stats.critical_findings > 0
                                ? 'bg-red-500'
                                : undefined
                        }
                    />
                    <StatCard
                        label="Active repositories"
                        value={stats.active_repos}
                        icon={BarChart2}
                    />
                    <StatCard
                        label="Connected accounts"
                        value={stats.connected_accounts}
                        icon={Link2}
                    />
                    <StatCard
                        label="High-risk PRs"
                        value={stats.high_risk_prs}
                        icon={AlertTriangle}
                        accent={
                            stats.high_risk_prs > 0
                                ? 'text-red-600 dark:text-red-400'
                                : undefined
                        }
                        iconAccent={
                            stats.high_risk_prs > 0 ? 'bg-red-500' : undefined
                        }
                    />
                    <StatCard
                        label="Avg review duration"
                        value={Math.round(stats.avg_review_duration_ms / 1000)}
                        icon={Clock}
                        formatValue={(v) => `${v}s`}
                    />
                    <StatCard
                        label="Resolved findings"
                        value={stats.resolved_findings}
                        icon={CheckCircle}
                        accent="text-green-600 dark:text-green-400"
                        iconAccent={
                            stats.resolved_findings > 0
                                ? 'bg-green-500'
                                : undefined
                        }
                    />
                    <StatCard
                        label="Request-changes reviews"
                        value={stats.request_changes_reviews}
                        icon={XCircle}
                        accent={
                            stats.request_changes_reviews > 0
                                ? 'text-orange-600 dark:text-orange-400'
                                : undefined
                        }
                        iconAccent={
                            stats.request_changes_reviews > 0
                                ? 'bg-orange-500'
                                : undefined
                        }
                    />
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
