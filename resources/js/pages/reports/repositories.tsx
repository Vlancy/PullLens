import { Head, Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

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
    high_risk_prs: number;
    total_reviews: number;
    approve_rate: number;
    resolved_findings: number;
};

type Props = {
    repositories: RepoStat[];
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function timeAgo(iso: string | null): string {
    if (!iso) return '—';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60_000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

function capitalize(s: string | null): string {
    if (!s) return '—';
    return s.charAt(0).toUpperCase() + s.slice(1);
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

// ─── Main component ───────────────────────────────────────────────────────────

export default function ReportsRepositories({ repositories }: Props) {
    return (
        <>
            <Head title="Reports — Repositories" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                <div>
                    <h1 className="text-xl font-semibold">Reports</h1>
                    <p className="text-sm text-muted-foreground">
                        Per-repository PR and finding statistics
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
                                            <th className="px-4 py-3">
                                                Repository
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Open PRs
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Merged PRs
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Total Findings
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Reviews
                                            </th>
                                            <th className="px-4 py-3 text-right">
                                                Approve Rate
                                            </th>
                                            <th className="px-4 py-3">
                                                Top Bug Category
                                            </th>
                                            <th className="px-4 py-3">
                                                Last Activity
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {repositories.map((repo) => (
                                            <tr
                                                key={repo.id}
                                                className="hover:bg-muted/40"
                                            >
                                                {/* Repository */}
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <p className="font-medium">
                                                            {repo.full_name}
                                                        </p>
                                                        {repo.web_url && (
                                                            <a
                                                                href={
                                                                    repo.web_url
                                                                }
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
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <span
                                                            className={
                                                                repo.total_findings >
                                                                0
                                                                    ? 'font-medium text-amber-600 dark:text-amber-400'
                                                                    : 'text-muted-foreground'
                                                            }
                                                        >
                                                            {repo.total_findings}
                                                        </span>
                                                        {repo.high_risk_prs > 0 && (
                                                            <span className="inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400">
                                                                {repo.high_risk_prs} high-risk
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Reviews */}
                                                <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                                                    {repo.total_reviews}
                                                </td>

                                                {/* Approve rate */}
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {repo.total_reviews > 0 ? (
                                                        <span className="font-medium text-green-600 dark:text-green-400">
                                                            {repo.approve_rate}%
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </td>

                                                {/* Top Bug Category */}
                                                <td className="px-4 py-3">
                                                    {repo.top_category ? (
                                                        <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                                            {capitalize(
                                                                repo.top_category,
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Last Activity */}
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {timeAgo(repo.last_pr_at)}
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

ReportsRepositories.layout = {
    breadcrumbs: [
        { title: 'Reports', href: '/reports' },
        { title: 'Repositories', href: '/reports/repositories' },
    ],
};
