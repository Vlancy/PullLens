import { Form, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    Building2,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    GitBranch,
    GitMerge,
    GitPullRequest,
    GitPullRequestClosed,
    GitPullRequestDraft,
    Globe,
    Lock,
    RefreshCw,
    Search,
    Settings,
    Trash2,
    User as UserIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

export type TrackedRepository = {
    id: number;
    provider: string;
    account_id: number;
    installation_id: number | null;
    provider_repo_id: number;
    name: string;
    full_name: string;
    owner_login: string;
    owner_type: string | null;
    default_branch: string | null;
    is_private: boolean;
    web_url: string | null;
    reviews_enabled: boolean;
    branches_count: number;
    open_prs_count: number;
    draft_prs_count: number;
    merged_prs_count: number;
    closed_prs_count: number;
    settings_url: string;
    destroy_url: string;
};

export type AccountOption = {
    id: number;
    label: string;
};

type AvailableRepository = {
    provider_repo_id: number;
    name: string;
    full_name: string;
    default_branch: string;
    private: boolean;
    web_url: string | null;
};

type Installation = {
    installation_id: number;
    account_login: string;
    account_type: string;
    account_avatar_url: string | null;
    repositories: AvailableRepository[];
};

type BrowseState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'loaded'; installations: Installation[] };

// Cache loaded repositories per account so an accidental page refresh restores
// them instantly instead of hitting the provider's API again. Scoped to the
// tab (sessionStorage) and short-lived so the list never goes badly stale.
const BROWSE_CACHE_TTL_MS = 10 * 60 * 1000;

function browseCacheKey(browseUrl: string, accountId: number): string {
    return `pulllens:repos:${browseUrl}:${accountId}`;
}

function readBrowseCache(
    browseUrl: string,
    accountId: number,
): Installation[] | null {
    try {
        const raw = sessionStorage.getItem(
            browseCacheKey(browseUrl, accountId),
        );

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as {
            at: number;
            installations: Installation[];
        };

        if (Date.now() - parsed.at > BROWSE_CACHE_TTL_MS) {
            sessionStorage.removeItem(browseCacheKey(browseUrl, accountId));

            return null;
        }

        return parsed.installations;
    } catch {
        return null;
    }
}

function writeBrowseCache(
    browseUrl: string,
    accountId: number,
    installations: Installation[],
): void {
    try {
        sessionStorage.setItem(
            browseCacheKey(browseUrl, accountId),
            JSON.stringify({ at: Date.now(), installations }),
        );
    } catch {
        // Ignore storage being unavailable or over quota — caching is best-effort.
    }
}

export default function GitRepositoryManager({
    providerLabel,
    accounts,
    browseUrl,
    storeUrl,
    tracked,
}: {
    providerLabel: string;
    accounts: AccountOption[];
    browseUrl: string;
    storeUrl: string;
    tracked: TrackedRepository[];
}) {
    function trackedSelection(forAccountId: number): Set<number> {
        return new Set(
            tracked
                .filter((repo) => repo.account_id === forAccountId)
                .map((repo) => repo.provider_repo_id),
        );
    }

    const accountId = accounts[0].id;

    const [browse, setBrowse] = useState<BrowseState>(() => {
        const cached = readBrowseCache(browseUrl, accountId);

        return cached
            ? { status: 'loaded', installations: cached }
            : { status: 'idle' };
    });
    const [selected, setSelected] = useState<Set<number>>(() =>
        browse.status === 'loaded'
            ? trackedSelection(accounts[0].id)
            : new Set(),
    );
    const [saving, setSaving] = useState(false);

    const installationById = useMemo(() => {
        if (browse.status !== 'loaded') {
            return new Map<number, number>();
        }

        const map = new Map<number, number>();

        for (const installation of browse.installations) {
            for (const repo of installation.repositories) {
                map.set(repo.provider_repo_id, installation.installation_id);
            }
        }

        return map;
    }, [browse]);

    function applyLoaded(installations: Installation[]) {
        // Pre-check repositories already tracked for the chosen account.
        setSelected(trackedSelection(accountId));
        setBrowse({ status: 'loaded', installations });
    }

    async function loadRepositories({ force = false } = {}) {
        if (!force) {
            const cached = readBrowseCache(browseUrl, accountId);

            if (cached) {
                applyLoaded(cached);

                return;
            }
        }

        setBrowse({ status: 'loading' });

        try {
            const response = await fetch(browseUrl, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                const body = (await response.json().catch(() => null)) as {
                    message?: string;
                } | null;

                setBrowse({
                    status: 'error',
                    message:
                        body?.message ??
                        'Could not load repositories. Please try again.',
                });

                return;
            }

            const data = (await response.json()) as {
                installations: Installation[];
            };

            writeBrowseCache(browseUrl, accountId, data.installations);
            applyLoaded(data.installations);
        } catch {
            setBrowse({
                status: 'error',
                message: 'Could not reach the server. Please try again.',
            });
        }
    }

    function toggle(providerRepoId: number, checked: boolean) {
        setSelected((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(providerRepoId);
            } else {
                next.delete(providerRepoId);
            }

            return next;
        });
    }

    function toggleMany(providerRepoIds: number[], checked: boolean) {
        setSelected((current) => {
            const next = new Set(current);

            for (const id of providerRepoIds) {
                if (checked) {
                    next.add(id);
                } else {
                    next.delete(id);
                }
            }

            return next;
        });
    }

    function saveSelection() {
        const repositories = Array.from(selected)
            .map((providerRepoId) => ({
                provider_repo_id: providerRepoId,
                installation_id: installationById.get(providerRepoId),
            }))
            .filter(
                (
                    repo,
                ): repo is {
                    provider_repo_id: number;
                    installation_id: number;
                } => typeof repo.installation_id === 'number',
            );

        router.post(
            storeUrl,
            { account_id: accountId, repositories },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-3 rounded-lg border p-4">
                <div className="flex flex-col gap-1">
                    <p className="text-sm font-medium">
                        Pick repositories to track
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Load the repositories your {providerLabel} App can
                        access — across your personal account and your
                        organizations — then choose which ones PullLens should
                        track.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            loadRepositories({
                                force: browse.status === 'loaded',
                            })
                        }
                        disabled={browse.status === 'loading'}
                    >
                        {browse.status === 'loading' ? (
                            <Spinner className="size-4" />
                        ) : (
                            <RefreshCw className="size-4" />
                        )}
                        {browse.status === 'loaded'
                            ? 'Reload repositories'
                            : 'Load repositories'}
                    </Button>
                </div>

                {browse.status === 'error' && (
                    <p className="inline-flex items-center gap-1.5 text-sm text-destructive">
                        <AlertCircle className="size-4" />
                        {browse.message}
                    </p>
                )}

                {browse.status === 'loaded' && (
                    <RepositoryPicker
                        installations={browse.installations}
                        selected={selected}
                        onToggle={toggle}
                        onToggleMany={toggleMany}
                        onSave={saveSelection}
                        saving={saving}
                        selectedCount={selected.size}
                    />
                )}
            </div>

            <TrackedRepositoriesList tracked={tracked} />
        </div>
    );
}

const PAGE_SIZE = 10;

function RepositoryPicker({
    installations,
    selected,
    onToggle,
    onToggleMany,
    onSave,
    saving,
    selectedCount,
}: {
    installations: Installation[];
    selected: Set<number>;
    onToggle: (providerRepoId: number, checked: boolean) => void;
    onToggleMany: (providerRepoIds: number[], checked: boolean) => void;
    onSave: () => void;
    saving: boolean;
    selectedCount: number;
}) {
    const [query, setQuery] = useState('');

    const needle = query.trim().toLowerCase();

    const groups = useMemo(
        () =>
            installations
                .map((installation) => ({
                    installation,
                    repositories:
                        needle === ''
                            ? installation.repositories
                            : installation.repositories.filter(
                                  (repo) =>
                                      repo.name
                                          .toLowerCase()
                                          .includes(needle) ||
                                      repo.full_name
                                          .toLowerCase()
                                          .includes(needle) ||
                                      installation.account_login
                                          .toLowerCase()
                                          .includes(needle),
                              ),
                }))
                .filter((group) => group.repositories.length > 0),
        [installations, needle],
    );

    const visibleIds = useMemo(
        () =>
            groups.flatMap((group) =>
                group.repositories.map((repo) => repo.provider_repo_id),
            ),
        [groups],
    );

    const filteredCount = visibleIds.length;
    const allVisibleSelected =
        filteredCount > 0 && visibleIds.every((id) => selected.has(id));

    const hasRepositories = installations.some(
        (installation) => installation.repositories.length > 0,
    );

    if (!hasRepositories) {
        return (
            <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                No repositories are available to this account yet. Use
                &ldquo;Install / select repositories&rdquo; above to grant the
                App access on GitHub, then reload.
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-56 flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search repositories by name or owner…"
                        className="pl-9"
                    />
                </div>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={filteredCount === 0}
                    onClick={() =>
                        onToggleMany(visibleIds, !allVisibleSelected)
                    }
                >
                    {allVisibleSelected ? 'Deselect all' : 'Select all'}
                </Button>
            </div>

            {groups.length === 0 ? (
                <div className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                    No repositories match &ldquo;{query}&rdquo;.
                </div>
            ) : (
                <div className="flex flex-col gap-3">
                    {groups.map((group) => (
                        <InstallationGroup
                            key={group.installation.installation_id}
                            installation={group.installation}
                            repositories={group.repositories}
                            selected={selected}
                            onToggle={onToggle}
                            onToggleMany={onToggleMany}
                            forceOpen={needle !== ''}
                            defaultExpanded={selectedCount === 0}
                        />
                    ))}
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {selectedCount} selected · {filteredCount}{' '}
                    {filteredCount === 1 ? 'repository' : 'repositories'} shown
                </p>
                <Button type="button" onClick={onSave} disabled={saving}>
                    {saving && <Spinner className="size-4" />}
                    Save selection
                </Button>
            </div>
        </div>
    );
}

function InstallationGroup({
    installation,
    repositories,
    selected,
    onToggle,
    onToggleMany,
    forceOpen,
    defaultExpanded = false,
}: {
    installation: Installation;
    repositories: AvailableRepository[];
    selected: Set<number>;
    onToggle: (providerRepoId: number, checked: boolean) => void;
    onToggleMany: (providerRepoIds: number[], checked: boolean) => void;
    forceOpen: boolean;
    defaultExpanded?: boolean;
}) {
    const [expanded, setExpanded] = useState(defaultExpanded);
    const [page, setPage] = useState(0);

    const open = forceOpen || expanded;

    const ids = repositories.map((repo) => repo.provider_repo_id);
    const selectedInGroup = ids.filter((id) => selected.has(id)).length;
    const groupState: boolean | 'indeterminate' =
        selectedInGroup === 0
            ? false
            : selectedInGroup === ids.length
              ? true
              : 'indeterminate';

    const pageCount = Math.max(1, Math.ceil(repositories.length / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount - 1);
    const start = currentPage * PAGE_SIZE;
    const pageRepos = repositories.slice(start, start + PAGE_SIZE);

    return (
        <div className="overflow-hidden rounded-md border">
            <div className="flex items-center gap-2 border-b bg-muted/40 px-3 py-2">
                <Checkbox
                    aria-label={`Select all in ${installation.account_login}`}
                    checked={groupState}
                    onCheckedChange={(checked) =>
                        onToggleMany(ids, checked === true)
                    }
                />
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    className="flex min-w-0 flex-1 items-center gap-2 text-left"
                >
                    {open ? (
                        <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                    ) : (
                        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                    )}
                    {installation.account_type === 'Organization' ? (
                        <Building2 className="size-4 shrink-0 text-muted-foreground" />
                    ) : (
                        <UserIcon className="size-4 shrink-0 text-muted-foreground" />
                    )}
                    <span className="truncate text-sm font-medium">
                        {installation.account_login}
                    </span>
                    <Badge variant="outline">{installation.account_type}</Badge>
                    <Badge variant="secondary" className="ml-auto shrink-0">
                        {selectedInGroup}
                        <span className="text-muted-foreground">
                            {' / '}
                            {repositories.length}
                        </span>
                    </Badge>
                </button>
            </div>

            {open && (
                <>
                    <ul className="divide-y">
                        {pageRepos.map((repo) => (
                            <li
                                key={repo.provider_repo_id}
                                className="flex items-center gap-3 px-3 py-2"
                            >
                                <Checkbox
                                    id={`repo-${repo.provider_repo_id}`}
                                    checked={selected.has(
                                        repo.provider_repo_id,
                                    )}
                                    onCheckedChange={(checked) =>
                                        onToggle(
                                            repo.provider_repo_id,
                                            checked === true,
                                        )
                                    }
                                />
                                <label
                                    htmlFor={`repo-${repo.provider_repo_id}`}
                                    className="flex min-w-0 flex-1 cursor-pointer items-center gap-2"
                                >
                                    {(repo.private && (
                                        <Lock className="size-3 shrink-0 text-muted-foreground" />
                                    )) || (
                                        <Globe className="size-3 shrink-0 text-muted-foreground" />
                                    )}
                                    <span className="truncate text-sm">
                                        {repo.name}
                                    </span>
                                    {repo.web_url && (
                                        <a
                                            href={repo.web_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label={`Open ${repo.full_name} on GitHub`}
                                            className="shrink-0 text-muted-foreground transition hover:text-foreground"
                                        >
                                            <ExternalLink className="size-3" />
                                        </a>
                                    )}
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto shrink-0"
                                    >
                                        <GitBranch className="size-3" />
                                        {repo.default_branch}
                                    </Badge>
                                </label>
                            </li>
                        ))}
                    </ul>

                    {pageCount > 1 && (
                        <div className="flex items-center justify-between gap-3 border-t px-3 py-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={currentPage === 0}
                                onClick={() => setPage(currentPage - 1)}
                            >
                                <ChevronLeft className="size-4" />
                                Previous
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                Page {currentPage + 1} of {pageCount}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={currentPage >= pageCount - 1}
                                onClick={() => setPage(currentPage + 1)}
                            >
                                Next
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function TrackedRepositoriesList({
    tracked,
}: {
    tracked: TrackedRepository[];
}) {
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(0);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return tracked;
        }

        return tracked.filter(
            (repo) =>
                repo.full_name.toLowerCase().includes(needle) ||
                repo.name.toLowerCase().includes(needle) ||
                repo.owner_login.toLowerCase().includes(needle),
        );
    }, [tracked, query]);

    const pageCount = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount - 1);
    const start = currentPage * PAGE_SIZE;
    const pageRepos = filtered.slice(start, start + PAGE_SIZE);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
                <p className="text-sm font-medium">Tracked repositories</p>
                <Badge variant="secondary">{tracked.length}</Badge>
            </div>

            {tracked.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No repositories are tracked yet. Load and save a selection
                    above to start tracking.
                </p>
            ) : (
                <>
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setPage(0);
                            }}
                            placeholder="Search tracked repositories…"
                            className="pl-9"
                        />
                    </div>

                    {filtered.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No tracked repositories match “{query}”.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {pageRepos.map((repo) => (
                                <li
                                    key={repo.id}
                                    className="flex items-center justify-between gap-3 px-3 py-2.5"
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <span
                                                    className={`size-2 shrink-0 rounded-full ${repo.reviews_enabled ? 'bg-green-500' : 'bg-yellow-400'}`}
                                                />
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {repo.reviews_enabled
                                                    ? 'Watched'
                                                    : 'Paused'}
                                            </TooltipContent>
                                        </Tooltip>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <a
                                                    href={
                                                        repo.web_url
                                                            ? repo.web_url
                                                                  .split('/')
                                                                  .slice(0, -1)
                                                                  .join('/')
                                                            : '#'
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="shrink-0 text-muted-foreground hover:text-foreground"
                                                >
                                                    {repo.owner_type ===
                                                    'Organization' ? (
                                                        <Building2 className="size-4" />
                                                    ) : (
                                                        <UserIcon className="size-4" />
                                                    )}
                                                </a>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {repo.owner_type ?? 'User'}
                                            </TooltipContent>
                                        </Tooltip>
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-1.5">
                                                <Link
                                                    href={repo.settings_url}
                                                    className="truncate text-sm font-medium hover:underline"
                                                >
                                                    {repo.full_name}
                                                </Link>
                                                {repo.web_url && (
                                                    <a
                                                        href={repo.web_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="shrink-0 text-muted-foreground hover:text-foreground"
                                                        aria-label="Open on GitHub"
                                                    >
                                                        <ExternalLink className="size-3" />
                                                    </a>
                                                )}
                                            </div>
                                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                                {repo.default_branch && (
                                                    <span className="inline-flex items-center gap-1">
                                                        <GitBranch className="size-3" />
                                                        {repo.default_branch}
                                                    </span>
                                                )}
                                                <span>
                                                    {repo.branches_count} branch
                                                    {repo.branches_count === 1
                                                        ? ''
                                                        : 'es'}{' '}
                                                    stored
                                                </span>
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {repo.is_private ? (
                                            <Badge variant="outline">
                                                <Lock className="size-3" />
                                                Private
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                <Globe className="size-3" />
                                                Public
                                            </Badge>
                                        )}

                                        <PrStatsBadges repo={repo} />

                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="icon"
                                            aria-label="Repository settings"
                                        >
                                            <Link href={repo.settings_url}>
                                                <Settings className="size-4" />
                                            </Link>
                                        </Button>
                                        <RemoveRepositoryButton repo={repo} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    {pageCount > 1 && (
                        <div className="flex items-center justify-between gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={currentPage === 0}
                                onClick={() => setPage(currentPage - 1)}
                            >
                                <ChevronLeft className="size-4" />
                                Previous
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                Page {currentPage + 1} of {pageCount}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={currentPage >= pageCount - 1}
                                onClick={() => setPage(currentPage + 1)}
                            >
                                Next
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function PrStatsBadges({ repo }: { repo: TrackedRepository }) {
    const stats = [
        {
            count: repo.open_prs_count,
            icon: GitPullRequest,
            label: 'open',
            className: 'text-green-600 dark:text-green-400',
        },
        {
            count: repo.draft_prs_count,
            icon: GitPullRequestDraft,
            label: 'draft',
            className: 'text-muted-foreground',
        },
        {
            count: repo.merged_prs_count,
            icon: GitMerge,
            label: 'merged',
            className: 'text-purple-600 dark:text-purple-400',
        },
        {
            count: repo.closed_prs_count,
            icon: GitPullRequestClosed,
            label: 'closed',
            className: 'text-rose-600 dark:text-rose-400',
        },
    ].filter((s) => s.count > 0);

    if (stats.length === 0) {
        return null;
    }

    return (
        <div className="hidden items-center gap-1.5 sm:flex">
            {stats.map(({ count, icon: Icon, label, className }) => (
                <Tooltip key={label}>
                    <TooltipTrigger asChild>
                        <span
                            className={`inline-flex items-center gap-0.5 text-xs font-medium tabular-nums ${className}`}
                        >
                            <Icon className="size-3.5 shrink-0" />
                            {count}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>
                        {count} {label} PR{count === 1 ? '' : 's'}
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}

function RemoveRepositoryButton({ repo }: { repo: TrackedRepository }) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="icon" aria-label="Stop tracking">
                    <Trash2 className="size-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Stop tracking {repo.full_name}?</DialogTitle>
                    <DialogDescription>
                        PullLens removes this repository and its stored
                        branches. You can add it back later from the picker.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Form
                        action={repo.destroy_url}
                        method="delete"
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button variant="destructive" disabled={processing}>
                                {processing ? (
                                    <Spinner className="size-4" />
                                ) : (
                                    <Check className="size-4" />
                                )}
                                Stop tracking
                            </Button>
                        )}
                    </Form>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
