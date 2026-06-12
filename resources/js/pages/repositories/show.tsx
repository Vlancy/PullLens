import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Bot,
    CheckCircle2,
    CircleDot,
    ExternalLink,
    FileDiff,
    GitMerge,
    GitPullRequest,
    Lock,
    Settings,
    ShieldAlert,
    Unlock,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { ElementType } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { edit as repositorySettings } from '@/routes/integrations/repositories/settings';
import { index as repositoriesIndex } from '@/routes/repositories';

// ─── Types ───────────────────────────────────────────────────────────────────

type Repository = {
    id: string;
    full_name: string;
    name: string;
    owner_login: string;
    provider: string | null;
    is_private: boolean;
    web_url: string | null;
    default_branch: string | null;
    reviews_enabled: boolean;
};

type Stats = {
    total_prs: number;
    open_prs: number;
    merged_prs: number;
    draft_prs: number;
    closed_prs: number;
    total_reviews: number;
    total_findings: number;
    critical_high_findings: number;
};

type LatestReview = {
    verdict: string | null;
    verdict_label: string | null;
    reviewed_at: string | null;
};

type PullRequestItem = {
    id: string;
    number: number;
    title: string;
    state: string | null;
    is_draft: boolean;
    author_login: string | null;
    author_avatar_url: string | null;
    source_branch: string | null;
    target_branch: string | null;
    web_url: string | null;
    additions: number | null;
    deletions: number | null;
    changed_files_count: number | null;
    labels: string[];
    opened_at: string | null;
    merged_at: string | null;
    closed_at: string | null;
    findings_count: number;
    latest_review: LatestReview | null;
};

type Finding = {
    id: string;
    title: string;
    severity: string | null;
    category: string | null;
    file: string | null;
    line: number | null;
    is_resolved: boolean;
    pull_request: {
        number: number;
        title: string;
        web_url: string | null;
    } | null;
};

type Props = {
    repository: Repository;
    stats: Stats;
    findings_by_severity: Record<string, number>;
    pull_requests: PullRequestItem[];
    recent_findings: Finding[];
};

type StateFilter = 'all' | 'open' | 'draft' | 'merged' | 'closed';

// ─── Config ───────────────────────────────────────────────────────────────────

const verdictConfig: Record<string, { label: string; badge: string }> = {
    approve: {
        label: 'Approved',
        badge: 'bg-green-100 text-green-800 border-transparent dark:bg-green-900/40 dark:text-green-400',
    },
    comment: { label: 'Commented', badge: '' },
    request_changes: {
        label: 'Changes requested',
        badge: 'bg-red-100 text-red-800 border-transparent dark:bg-red-900/40 dark:text-red-400',
    },
};

const severityConfig: Record<string, { label: string; dot: string }> = {
    critical: { label: 'Critical', dot: 'bg-red-500' },
    high: { label: 'High', dot: 'bg-orange-500' },
    medium: { label: 'Medium', dot: 'bg-yellow-500' },
    low: { label: 'Low', dot: 'bg-blue-400' },
    informational: { label: 'Informational', dot: 'bg-gray-400' },
};

const stateConfig: Record<
    string,
    { label: string; icon: ElementType; color: string }
> = {
    open: { label: 'Open', icon: CircleDot, color: 'text-green-500' },
    draft: { label: 'Draft', icon: FileDiff, color: 'text-muted-foreground' },
    merged: { label: 'Merged', icon: GitMerge, color: 'text-purple-500' },
    closed: { label: 'Closed', icon: XCircle, color: 'text-muted-foreground' },
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

function plural(n: number, word: string): string {
    return `${n} ${word}${n !== 1 ? 's' : ''}`;
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function StatCard({
    label,
    value,
    icon: Icon,
    accent,
    iconAccent,
}: {
    label: string;
    value: number | string;
    icon: ElementType;
    accent?: string;
    iconAccent?: string;
}) {
    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">{label}</p>
                        <p
                            className={`mt-1 text-3xl font-semibold tracking-tight ${accent ?? 'text-foreground'}`}
                        >
                            {value}
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

export default function RepositoryShow({
    repository,
    stats,
    pull_requests,
    recent_findings,
}: Props) {
    const [stateFilter, setStateFilter] = useState<StateFilter>('all');

    const filteredPRs =
        stateFilter === 'all'
            ? pull_requests
            : pull_requests.filter((pr) => pr.state === stateFilter);

    const hasCritical = stats.critical_high_findings > 0;

    const stateFilterOptions: {
        key: StateFilter;
        label: string;
        count: number;
    }[] = [
        { key: 'all', label: 'All', count: pull_requests.length },
        { key: 'open', label: 'Open', count: stats.open_prs },
        { key: 'draft', label: 'Draft', count: stats.draft_prs },
        { key: 'merged', label: 'Merged', count: stats.merged_prs },
        { key: 'closed', label: 'Closed', count: stats.closed_prs },
    ];

    return (
        <>
            <Head title={repository.full_name} />

            <div className="flex flex-1 flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <h1 className="truncate text-xl font-semibold">
                                {repository.full_name}
                            </h1>
                            {repository.web_url && (
                                <a
                                    href={repository.web_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="shrink-0 text-muted-foreground hover:text-foreground"
                                >
                                    <ExternalLink className="size-4" />
                                </a>
                            )}
                        </div>
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            {repository.provider && (
                                <Badge
                                    variant="outline"
                                    className="text-xs capitalize"
                                >
                                    {repository.provider}
                                </Badge>
                            )}
                            <Badge variant="outline" className="gap-1 text-xs">
                                {repository.is_private ? (
                                    <>
                                        <Lock className="size-3" /> Private
                                    </>
                                ) : (
                                    <>
                                        <Unlock className="size-3" /> Public
                                    </>
                                )}
                            </Badge>
                            {repository.default_branch && (
                                <Badge
                                    variant="outline"
                                    className="font-mono text-xs"
                                >
                                    {repository.default_branch}
                                </Badge>
                            )}
                            {!repository.reviews_enabled && (
                                <Badge variant="secondary" className="text-xs">
                                    Reviews paused
                                </Badge>
                            )}
                        </div>
                    </div>
                    <Link href={repositorySettings(repository.id).url}>
                        <Button
                            variant="outline"
                            size="sm"
                            className="shrink-0 gap-1.5"
                        >
                            <Settings className="size-3.5" />
                            Settings
                        </Button>
                    </Link>
                </div>

                {/* Critical alert */}
                {hasCritical && (
                    <div className="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-400">
                        <ShieldAlert className="size-4 shrink-0" />
                        <span>
                            <strong>{stats.critical_high_findings}</strong>{' '}
                            critical or high-severity{' '}
                            {stats.critical_high_findings === 1
                                ? 'finding requires'
                                : 'findings require'}{' '}
                            attention.
                        </span>
                    </div>
                )}

                {/* Stat cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Open PRs"
                        value={stats.open_prs}
                        icon={CircleDot}
                        accent="text-green-600 dark:text-green-400"
                    />
                    <StatCard
                        label="Merged PRs"
                        value={stats.merged_prs}
                        icon={GitMerge}
                        accent="text-purple-600 dark:text-purple-400"
                    />
                    <StatCard
                        label="AI reviews"
                        value={stats.total_reviews}
                        icon={Bot}
                        accent="text-primary"
                    />
                    <StatCard
                        label="Findings"
                        value={stats.total_findings}
                        icon={AlertTriangle}
                        accent={
                            hasCritical
                                ? 'text-red-600 dark:text-red-400'
                                : 'text-amber-600 dark:text-amber-400'
                        }
                        iconAccent={hasCritical ? 'bg-red-500' : undefined}
                    />
                </div>

                {/* Pull requests */}
                <Card>
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between gap-4">
                            <CardTitle className="text-sm font-medium">
                                Pull requests
                            </CardTitle>
                            <div className="flex gap-1">
                                {stateFilterOptions.map(
                                    ({ key, label, count }) => (
                                        <button
                                            key={key}
                                            onClick={() => setStateFilter(key)}
                                            className={`rounded-md px-2.5 py-1 text-xs transition-colors ${
                                                stateFilter === key
                                                    ? 'bg-muted font-medium text-foreground'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            {label}
                                            <span className="ml-1.5 text-muted-foreground tabular-nums">
                                                {count}
                                            </span>
                                        </button>
                                    ),
                                )}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {filteredPRs.length === 0 ? (
                            <p className="px-6 pb-6 text-sm text-muted-foreground">
                                No{' '}
                                {stateFilter === 'all' ? '' : stateFilter + ' '}
                                pull requests.
                            </p>
                        ) : (
                            <div className="divide-y divide-border">
                                {filteredPRs.map((pr) => {
                                    const sc = pr.state
                                        ? stateConfig[pr.state]
                                        : null;
                                    const vc = pr.latest_review?.verdict
                                        ? verdictConfig[
                                              pr.latest_review.verdict
                                          ]
                                        : null;
                                    const ts =
                                        pr.merged_at ??
                                        pr.closed_at ??
                                        pr.opened_at;
                                    const StateIcon =
                                        sc?.icon ?? GitPullRequest;

                                    return (
                                        <div
                                            key={pr.id}
                                            className="flex items-start gap-3 px-6 py-3"
                                        >
                                            <Avatar className="mt-0.5 size-7 shrink-0">
                                                <AvatarImage
                                                    src={
                                                        pr.author_avatar_url ??
                                                        undefined
                                                    }
                                                />
                                                <AvatarFallback className="text-xs">
                                                    {pr.author_login
                                                        ?.slice(0, 2)
                                                        .toUpperCase() ?? '?'}
                                                </AvatarFallback>
                                            </Avatar>

                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    {sc && (
                                                        <StateIcon
                                                            className={`size-3 ${sc.color}`}
                                                        />
                                                    )}
                                                    <span>#{pr.number}</span>
                                                    {pr.source_branch &&
                                                        pr.target_branch && (
                                                            <>
                                                                <span>·</span>
                                                                <span className="font-mono">
                                                                    {
                                                                        pr.source_branch
                                                                    }
                                                                </span>
                                                                <ArrowRight className="size-3" />
                                                                <span className="font-mono">
                                                                    {
                                                                        pr.target_branch
                                                                    }
                                                                </span>
                                                            </>
                                                        )}
                                                </div>

                                                <p className="mt-0.5 truncate text-sm leading-snug font-medium">
                                                    {pr.web_url ? (
                                                        <a
                                                            href={pr.web_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="hover:underline"
                                                        >
                                                            {pr.title}
                                                        </a>
                                                    ) : (
                                                        pr.title
                                                    )}
                                                </p>

                                                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                                                    {pr.author_login && (
                                                        <span>
                                                            by {pr.author_login}
                                                        </span>
                                                    )}
                                                    {pr.changed_files_count !=
                                                        null && (
                                                        <span>
                                                            {plural(
                                                                pr.changed_files_count,
                                                                'file',
                                                            )}
                                                        </span>
                                                    )}
                                                    {(pr.additions != null ||
                                                        pr.deletions !=
                                                            null) && (
                                                        <span>
                                                            <span className="text-green-600">
                                                                +
                                                                {pr.additions ??
                                                                    0}
                                                            </span>{' '}
                                                            <span className="text-red-500">
                                                                -
                                                                {pr.deletions ??
                                                                    0}
                                                            </span>
                                                        </span>
                                                    )}
                                                    {pr.findings_count > 0 && (
                                                        <span className="text-amber-600">
                                                            {plural(
                                                                pr.findings_count,
                                                                'finding',
                                                            )}
                                                        </span>
                                                    )}
                                                    <span>{timeAgo(ts)}</span>
                                                </div>

                                                {pr.labels &&
                                                    pr.labels.length > 0 && (
                                                        <div className="mt-1 flex flex-wrap gap-1">
                                                            {pr.labels.map(
                                                                (label) => (
                                                                    <span
                                                                        key={
                                                                            label
                                                                        }
                                                                        className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-xs"
                                                                    >
                                                                        {label}
                                                                    </span>
                                                                ),
                                                            )}
                                                        </div>
                                                    )}
                                            </div>

                                            {vc && (
                                                <Badge
                                                    variant="secondary"
                                                    className={`shrink-0 text-xs ${vc.badge}`}
                                                >
                                                    {vc.label}
                                                </Badge>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Recent findings */}
                {recent_findings.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Recent findings
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="divide-y divide-border">
                                {recent_findings.map((finding) => {
                                    const sc = finding.severity
                                        ? severityConfig[finding.severity]
                                        : null;

                                    return (
                                        <div
                                            key={finding.id}
                                            className="flex items-start gap-3 px-6 py-3"
                                        >
                                            {sc && (
                                                <div
                                                    className={`mt-1.5 size-2 shrink-0 rounded-full ${sc.dot}`}
                                                />
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm leading-snug font-medium">
                                                    {finding.title}
                                                </p>
                                                <div className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                                                    {sc && (
                                                        <span>{sc.label}</span>
                                                    )}
                                                    {finding.category && (
                                                        <span className="capitalize">
                                                            {finding.category}
                                                        </span>
                                                    )}
                                                    {finding.file && (
                                                        <span className="truncate font-mono">
                                                            {finding.file}
                                                            {finding.line
                                                                ? `:${finding.line}`
                                                                : ''}
                                                        </span>
                                                    )}
                                                    {finding.pull_request && (
                                                        <>
                                                            <span>·</span>
                                                            {finding
                                                                .pull_request
                                                                .web_url ? (
                                                                <a
                                                                    href={
                                                                        finding
                                                                            .pull_request
                                                                            .web_url
                                                                    }
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    className="hover:underline"
                                                                >
                                                                    #
                                                                    {
                                                                        finding
                                                                            .pull_request
                                                                            .number
                                                                    }
                                                                </a>
                                                            ) : (
                                                                <span>
                                                                    #
                                                                    {
                                                                        finding
                                                                            .pull_request
                                                                            .number
                                                                    }
                                                                </span>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                            {finding.is_resolved && (
                                                <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-green-500" />
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

RepositoryShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Repositories', href: repositoriesIndex().url },
    ],
};
