import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    CalendarDays,
    CheckCircle,
    ExternalLink,
    GitBranch,
    GitCommitHorizontal,
    LayoutDashboard,
    Users,
    XCircle,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

// ─── Types ────────────────────────────────────────────────────────────────────

type RepoStat = {
    id: string;
    name: string;
    full_name: string;
    web_url: string | null;
    total_prs: number;
    merged_prs: number;
    open_prs: number;
    total_findings: number;
    top_category: string | null;
    last_pr_at: string | null;
    total_reviews: number;
    approve_rate: number;
    resolved_findings: number;
};

type Props = {
    repositories: RepoStat[];
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function timeAgo(iso: string | null): string {
    if (!iso) {
return '—';
}

    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60_000);

    if (mins < 1) {
return 'just now';
}

    if (mins < 60) {
return `${mins}m ago`;
}

    const hours = Math.floor(mins / 60);

    if (hours < 24) {
return `${hours}h ago`;
}

    return `${Math.floor(hours / 24)}d ago`;
}

function capitalize(s: string | null): string {
    if (!s) {
return '—';
}

    return s.charAt(0).toUpperCase() + s.slice(1);
}

function repoHealth(repo: RepoStat): 'healthy' | 'warning' | 'critical' {
    if (repo.total_findings > 10) {
return 'critical';
}

    if (repo.open_prs > 5 || repo.total_findings > 3) {
return 'warning';
}

    return 'healthy';
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

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsRepositories({ repositories }: Props) {
    return (
        <>
            <Head title="Reports — Repositories" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">Repository Health</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        PR activity, review coverage, and bug density per repository
                    </p>
                </div>

                <ReportsNav active="/reports/repositories" />

                {repositories.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <p className="text-sm">
                            No active repositories with data yet.
                        </p>
                    </div>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                                            <th className="px-4 py-3">Repository</th>
                                            <th className="px-4 py-3">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Health</TooltipTrigger>
                                                    <TooltipContent className="max-w-52 text-center">Based on finding rate and approval rate. Green = healthy, Red = needs attention.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3 text-right">Open PRs</th>
                                            <th className="px-4 py-3 text-right">Merged PRs</th>
                                            <th className="px-4 py-3 text-right">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Total Findings</TooltipTrigger>
                                                    <TooltipContent className="max-w-48 text-center">Total code issues flagged by AI reviews across all PRs.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Reviews</TooltipTrigger>
                                                    <TooltipContent className="max-w-44 text-center">Number of AI reviews completed.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Approve Rate</TooltipTrigger>
                                                    <TooltipContent className="max-w-52 text-center">% of AI reviews that approved the PR vs requested changes.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Top Bug Category</TooltipTrigger>
                                                    <TooltipContent className="max-w-48 text-center">Most common issue category found in AI review findings.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                            <th className="px-4 py-3">
                                                <TooltipProvider><Tooltip>
                                                    <TooltipTrigger className="underline decoration-dotted cursor-help">Last Activity</TooltipTrigger>
                                                    <TooltipContent className="max-w-44 text-center">Date of the most recent pull request.</TooltipContent>
                                                </Tooltip></TooltipProvider>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {repositories.map((repo) => {
                                            const health = repoHealth(repo);
                                            const rowClass = health === 'critical'
                                                ? 'bg-red-50/40 dark:bg-red-950/10 hover:bg-red-50/60 dark:hover:bg-red-950/20'
                                                : health === 'warning'
                                                    ? 'bg-yellow-50/30 dark:bg-yellow-950/10 hover:bg-yellow-50/50 dark:hover:bg-yellow-950/20'
                                                    : 'hover:bg-muted/40';

                                            return (
                                                <tr key={repo.id} className={rowClass}>
                                                    {/* Repository */}
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-2">
                                                            {repo.web_url ? (
                                                                <a
                                                                    href={repo.web_url}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    className="font-medium hover:underline"
                                                                >
                                                                    {repo.full_name}
                                                                </a>
                                                            ) : (
                                                                <p className="font-medium">{repo.full_name}</p>
                                                            )}
                                                            {repo.web_url && (
                                                                <a
                                                                    href={repo.web_url}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    className="shrink-0 text-muted-foreground hover:text-foreground"
                                                                    title="Open on GitHub"
                                                                >
                                                                    <ExternalLink className="size-3.5" />
                                                                </a>
                                                            )}
                                                        </div>
                                                    </td>

                                                    {/* Health */}
                                                    <td className="px-4 py-3">
                                                        {health === 'healthy' && (
                                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400">
                                                                <CheckCircle className="size-3" /> Healthy
                                                            </span>
                                                        )}
                                                        {health === 'warning' && (
                                                            <span className="inline-flex items-center gap-1 text-xs font-medium text-yellow-700 dark:text-yellow-400">
                                                                <AlertCircle className="size-3" /> Warning
                                                            </span>
                                                        )}
                                                        {health === 'critical' && (
                                                            <Link
                                                                href={`/findings?repository_id=${repo.id}&status=open`}
                                                                className="inline-flex items-center gap-1 text-xs font-medium text-red-700 hover:underline dark:text-red-400"
                                                            >
                                                                <XCircle className="size-3" /> Needs attention
                                                            </Link>
                                                        )}
                                                    </td>

                                                    {/* Open PRs */}
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        <span className="font-medium text-green-600 dark:text-green-400">
                                                            {repo.open_prs}
                                                        </span>
                                                    </td>

                                                    {/* Merged PRs */}
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        <span className="font-medium text-purple-600 dark:text-purple-400">
                                                            {repo.merged_prs}
                                                        </span>
                                                    </td>

                                                    {/* Total Findings */}
                                                    <td className="px-4 py-3 text-right tabular-nums">
                                                        {repo.total_findings > 0 ? (
                                                            <Link
                                                                href={`/findings?repository_id=${repo.id}&status=open`}
                                                                className="font-medium text-amber-600 hover:underline dark:text-amber-400"
                                                            >
                                                                {repo.total_findings}
                                                            </Link>
                                                        ) : (
                                                            <span className="text-muted-foreground">0</span>
                                                        )}
                                                    </td>

                                                    {/* Reviews */}
                                                    <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                                        {repo.total_reviews}
                                                    </td>

                                                    {/* Approve rate */}
                                                    <td className="px-4 py-3">
                                                        {repo.total_reviews > 0 ? (
                                                            <div className="flex items-center gap-2">
                                                                <div className="h-1.5 w-16 rounded-full bg-muted overflow-hidden">
                                                                    <div
                                                                        className="h-full rounded-full bg-green-500"
                                                                        style={{ width: `${repo.approve_rate}%` }}
                                                                    />
                                                                </div>
                                                                <span className="text-xs tabular-nums">{repo.approve_rate}%</span>
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>

                                                    {/* Top Bug Category */}
                                                    <td className="px-4 py-3">
                                                        {repo.top_category ? (
                                                            <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                                                {capitalize(repo.top_category)}
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>

                                                    {/* Last Activity */}
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {timeAgo(repo.last_pr_at)}
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

ReportsRepositories.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Repositories', href: '/reports/repositories' },
    ],
};
