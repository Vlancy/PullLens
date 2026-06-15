import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CircleDot,
    ExternalLink,
    GitPullRequest,
    Lock,
    Search,
    Settings,
    Unlock,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { edit as repositorySettings } from '@/routes/integrations/repositories/settings';
import {
    index as repositoriesIndex,
    show as repositoryShow,
} from '@/routes/repositories';

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
    open_prs_count: number;
    total_prs_count: number;
    findings_count: number;
};

type Props = {
    repositories: Repository[];
};

type RepoFilter = 'has_open' | 'all';

// ─── Main component ───────────────────────────────────────────────────────────

export default function RepositoriesIndex({ repositories }: Props) {
    const [search, setSearch] = useState('');
    const [repoFilter, setRepoFilter] = useState<RepoFilter>('has_open');

    const withOpenPRs = repositories.filter((r) => r.open_prs_count > 0);

    const afterFilter =
        repoFilter === 'has_open' ? withOpenPRs : repositories;

    const filtered = afterFilter.filter((r) =>
        r.full_name.toLowerCase().includes(search.toLowerCase()),
    );

    const filterOptions: { key: RepoFilter; label: string; count: number }[] =
        [
            { key: 'has_open', label: 'Has open PRs', count: withOpenPRs.length },
            { key: 'all', label: 'All', count: repositories.length },
        ];

    return (
        <>
            <Head title="Repositories" />

            <div className="flex flex-1 flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Repositories</h1>
                        <p className="text-sm text-muted-foreground">
                            {repositories.length} tracked{' '}
                            {repositories.length === 1
                                ? 'repository'
                                : 'repositories'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="flex gap-1 rounded-lg border border-border p-1">
                            {filterOptions.map(({ key, label, count }) => (
                                <button
                                    key={key}
                                    onClick={() => setRepoFilter(key)}
                                    className={`rounded-md px-3 py-1 text-xs transition-colors ${
                                        repoFilter === key
                                            ? 'bg-muted font-medium text-foreground'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {label}
                                    <span className="ml-1.5 tabular-nums">
                                        {count}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <div className="relative w-full sm:w-56">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Filter repositories…"
                                className="pl-9"
                            />
                        </div>
                    </div>
                </div>

                {/* Repository list */}
                {filtered.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-16 text-center text-muted-foreground">
                        <GitPullRequest className="size-8 opacity-40" />
                        <p className="text-sm">
                            {search
                                ? 'No repositories match your search.'
                                : repoFilter === 'has_open'
                                  ? 'No repositories with open PRs.'
                                  : 'No repositories tracked yet.'}
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {filtered.map((repo) => (
                            <Card
                                key={repo.id}
                                className="group transition-shadow hover:shadow-md"
                            >
                                <CardContent className="pt-5">
                                    {/* Row 1: name + actions */}
                                    <div className="flex items-start justify-between gap-2">
                                        <Link
                                            href={repositoryShow(repo.id).url}
                                            className="min-w-0 flex-1"
                                        >
                                            <p className="truncate leading-snug font-medium group-hover:underline">
                                                {repo.full_name}
                                            </p>
                                        </Link>
                                        <div className="flex shrink-0 items-center gap-1">
                                            {repo.web_url && (
                                                <a
                                                    href={repo.web_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                                    title="Open on GitHub"
                                                >
                                                    <ExternalLink className="size-3.5" />
                                                </a>
                                            )}
                                            <Link
                                                href={repositorySettings(repo.id, { query: { from: 'repositories' } }).url}
                                                className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                                title="Repository settings"
                                            >
                                                <Settings className="size-3.5" />
                                            </Link>
                                        </div>
                                    </div>

                                    {/* Row 2: badges */}
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                        {repo.provider && (
                                            <Badge
                                                variant="outline"
                                                className="text-xs capitalize"
                                            >
                                                {repo.provider}
                                            </Badge>
                                        )}
                                        <Badge
                                            variant="outline"
                                            className="gap-1 text-xs"
                                        >
                                            {repo.is_private ? (
                                                <>
                                                    <Lock className="size-3" />
                                                    Private
                                                </>
                                            ) : (
                                                <>
                                                    <Unlock className="size-3" />
                                                    Public
                                                </>
                                            )}
                                        </Badge>
                                        {!repo.reviews_enabled && (
                                            <Badge
                                                variant="secondary"
                                                className="text-xs"
                                            >
                                                Reviews paused
                                            </Badge>
                                        )}
                                        {repo.default_branch && (
                                            <Badge
                                                variant="outline"
                                                className="font-mono text-xs"
                                            >
                                                {repo.default_branch}
                                            </Badge>
                                        )}
                                    </div>

                                    {/* Row 3: stats */}
                                    <div className="mt-4 flex items-center gap-4 text-sm text-muted-foreground">
                                        <span className="flex items-center gap-1.5">
                                            <CircleDot className="size-3.5 text-green-500" />
                                            <span className="font-medium text-foreground tabular-nums">
                                                {repo.open_prs_count}
                                            </span>
                                            open
                                        </span>
                                        <span className="flex items-center gap-1.5">
                                            <GitPullRequest className="size-3.5" />
                                            <span className="font-medium text-foreground tabular-nums">
                                                {repo.total_prs_count}
                                            </span>
                                            total PRs
                                        </span>
                                        {repo.findings_count > 0 && (
                                            <span className="flex items-center gap-1.5">
                                                <AlertTriangle className="size-3.5 text-amber-500" />
                                                <span className="font-medium text-foreground tabular-nums">
                                                    {repo.findings_count}
                                                </span>
                                                findings
                                            </span>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

RepositoriesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Repositories', href: repositoriesIndex().url },
    ],
};
