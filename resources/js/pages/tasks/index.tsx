import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    ExternalLink,
    GitPullRequest,
    Link2,
    ListChecks,
    MoreHorizontal,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

// ─── Types ────────────────────────────────────────────────────────────────────

type Option = { value: string; label: string };

type LinkedTask = {
    id: string;
    title: string;
    type: string | null;
    status: string | null;
    relation: string | null;
    relation_label: string | null;
    reason: string | null;
    confidence: number | null;
    source: string;
};

type Task = {
    id: string;
    title: string;
    description: string | null;
    type: string | null;
    type_label: string | null;
    status: string | null;
    status_label: string | null;
    first_time_right: boolean;
    estimated_hours: number | null;
    rework_count: number;
    revision_count: number;
    files: string[];
    delivered_at: string | null;
    author: {
        login: string | null;
        name: string | null;
        avatar_url: string | null;
    };
    external: {
        provider: string | null;
        provider_label: string | null;
        key: string;
        url: string | null;
    } | null;
    repository: { id: string; full_name: string } | null;
    pull_request: {
        number: number;
        title: string;
        web_url: string | null;
        state: string | null;
    } | null;
    links: LinkedTask[];
    inbound_links: LinkedTask[];
};

type Filters = {
    search: string;
    type: string;
    status: string;
    author: string;
    repo_id: string;
    period: string;
    rework: boolean;
    related_to: string;
};

type Props = {
    tasks: Task[];
    summary: {
        total: number;
        first_time_right: number;
        reworked: number;
        first_time_right_rate: number | null;
        estimated_hours: number;
    };
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    types: Option[];
    statuses: Option[];
    periods: Option[];
    authors: { author_login: string; author_name: string | null }[];
    repositories: { id: string; full_name: string }[];
};

/** Radix Select cannot hold an empty value, so "no filter" needs a sentinel. */
const ALL = 'all';

// ─── Styling maps ─────────────────────────────────────────────────────────────

const TYPE_STYLES: Record<string, string> = {
    feature: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    bugfix: 'bg-red-500/10 text-red-700 dark:text-red-400',
    refactor: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
    performance: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    security: 'bg-purple-500/10 text-purple-700 dark:text-purple-400',
    test: 'bg-cyan-500/10 text-cyan-700 dark:text-cyan-400',
    documentation: 'bg-slate-500/10 text-slate-700 dark:text-slate-300',
    chore: 'bg-muted text-muted-foreground',
};

const STATUS_STYLES: Record<string, string> = {
    in_progress: 'bg-muted text-muted-foreground',
    delivered: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    revised: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
    reworked: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    reverted: 'bg-red-500/10 text-red-700 dark:text-red-400',
};

function chip(map: Record<string, string>, key: string | null): string {
    return (key && map[key]) || 'bg-muted text-muted-foreground';
}

function initials(name: string): string {
    return name
        .split(' ')
        .map((part) => part[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function formatDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          })
        : 'Not delivered';
}

// ─── Pagination ───────────────────────────────────────────────────────────────

/** Marks a run of skipped pages in the number strip. */
const GAP = 'gap';

type PageSlot = number | typeof GAP;

/**
 * The pages to render: always the first and last, the current one and its
 * neighbours, and enough of the near edge that the strip does not jump in width
 * as you move through it. Runs of skipped pages collapse into a single gap.
 */
function pageWindow(current: number, last: number): PageSlot[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const wanted = new Set<number>([
        1,
        last,
        current - 1,
        current,
        current + 1,
    ]);

    // Keep the strip a constant width when the current page sits near an edge.
    if (current <= 4) {
        [2, 3, 4, 5].forEach((page) => wanted.add(page));
    }

    if (current >= last - 3) {
        [last - 1, last - 2, last - 3, last - 4].forEach((page) =>
            wanted.add(page),
        );
    }

    const pages = [...wanted]
        .filter((page) => page >= 1 && page <= last)
        .sort((a, b) => a - b);

    return pages.flatMap((page, index) =>
        index > 0 && page - pages[index - 1] > 1 ? [GAP, page] : [page],
    );
}

function Pagination({
    pagination,
    onNavigate,
}: {
    pagination: Props['pagination'];
    onNavigate: (page: number) => void;
}) {
    const {
        current_page: current,
        last_page: last,
        from,
        to,
        total,
    } = pagination;

    if (total === 0) {
        return null;
    }

    return (
        <div className="flex flex-col-reverse items-center justify-between gap-3 border-t pt-4 sm:flex-row">
            <p className="text-sm text-muted-foreground">
                Showing {from ?? 0}–{to ?? 0} of {total} task
                {total === 1 ? '' : 's'}
            </p>

            {last > 1 && (
                <nav
                    aria-label="Pagination"
                    className="flex items-center gap-1"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={current <= 1}
                        onClick={() => onNavigate(current - 1)}
                        aria-label="Previous page"
                    >
                        <ChevronLeft className="size-4" />
                        <span className="hidden sm:inline">Previous</span>
                    </Button>

                    {pageWindow(current, last).map((slot, index) =>
                        slot === GAP ? (
                            <span
                                key={`gap-${index}`}
                                aria-hidden="true"
                                className="px-1 text-muted-foreground"
                            >
                                <MoreHorizontal className="size-4" />
                            </span>
                        ) : (
                            <Button
                                key={slot}
                                variant={
                                    slot === current ? 'default' : 'outline'
                                }
                                size="sm"
                                className="w-9 tabular-nums"
                                aria-label={`Page ${slot}`}
                                aria-current={
                                    slot === current ? 'page' : undefined
                                }
                                onClick={() => onNavigate(slot)}
                            >
                                {slot}
                            </Button>
                        ),
                    )}

                    <Button
                        variant="outline"
                        size="sm"
                        disabled={current >= last}
                        onClick={() => onNavigate(current + 1)}
                        aria-label="Next page"
                    >
                        <span className="hidden sm:inline">Next</span>
                        <ChevronRight className="size-4" />
                    </Button>
                </nav>
            )}
        </div>
    );
}

// ─── Task history ─────────────────────────────────────────────────────────────

/**
 * The relationships around a task. Inbound links matter most - they are what tell
 * you the work came back - so they are listed first and coloured by relation.
 */
function TaskHistory({
    task,
    onFilterRelated,
}: {
    task: Task;
    onFilterRelated: (id: string) => void;
}) {
    const entries = [...task.inbound_links, ...task.links];

    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="mt-2 space-y-1 border-l-2 border-muted pl-3">
            {entries.map((link, index) => (
                <div
                    key={`${link.id}-${index}`}
                    className="flex flex-wrap items-center gap-1.5 text-xs"
                >
                    <Link2 className="size-3 shrink-0 text-muted-foreground" />
                    <span className="font-medium text-muted-foreground">
                        {link.relation_label}
                    </span>
                    <button
                        type="button"
                        onClick={() => onFilterRelated(link.id)}
                        className="truncate underline-offset-2 hover:underline"
                    >
                        {link.title}
                    </button>
                    {link.source === 'manual' && (
                        <Badge
                            variant="outline"
                            className="h-4 px-1 text-[10px]"
                        >
                            manual
                        </Badge>
                    )}
                    {link.reason && (
                        <span className="truncate text-muted-foreground">
                            - {link.reason}
                        </span>
                    )}
                </div>
            ))}
        </div>
    );
}

// ─── Task row ─────────────────────────────────────────────────────────────────

function TaskRow({
    task,
    onFilterRelated,
}: {
    task: Task;
    onFilterRelated: (id: string) => void;
}) {
    const authorName = task.author.name ?? task.author.login ?? 'Unknown';

    return (
        <Card>
            <CardContent className="space-y-2 p-4">
                <div className="flex flex-wrap items-start gap-2">
                    <span
                        className={`rounded px-1.5 py-0.5 text-xs font-medium ${chip(TYPE_STYLES, task.type)}`}
                    >
                        {task.type_label ?? 'Chore'}
                    </span>
                    <span
                        className={`rounded px-1.5 py-0.5 text-xs font-medium ${chip(STATUS_STYLES, task.status)}`}
                    >
                        {task.status_label ?? 'Unknown'}
                    </span>

                    {task.first_time_right && (
                        <span className="inline-flex items-center gap-1 rounded bg-emerald-500/10 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                            <CircleCheck className="size-3" />
                            First time right
                        </span>
                    )}

                    {task.rework_count > 0 && (
                        <span className="inline-flex items-center gap-1 rounded bg-amber-500/10 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">
                            <AlertTriangle className="size-3" />
                            {task.rework_count} fix
                            {task.rework_count === 1 ? '' : 'es'} after delivery
                        </span>
                    )}

                    {task.external && (
                        <a
                            href={task.external.url ?? '#'}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs hover:underline"
                        >
                            {task.external.key}
                            {task.external.url && (
                                <ExternalLink className="size-3" />
                            )}
                        </a>
                    )}

                    <span className="ml-auto text-xs text-muted-foreground">
                        {formatDate(task.delivered_at)}
                    </span>
                </div>

                <p className="font-medium">{task.title}</p>

                {task.description && (
                    <p className="text-sm text-muted-foreground">
                        {task.description}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1.5">
                        <Avatar className="size-4">
                            <AvatarImage
                                src={task.author.avatar_url ?? undefined}
                                alt={authorName}
                            />
                            <AvatarFallback className="text-[8px]">
                                {initials(authorName)}
                            </AvatarFallback>
                        </Avatar>
                        {authorName}
                    </span>

                    {task.repository && (
                        <span className="font-mono">
                            {task.repository.full_name}
                        </span>
                    )}

                    {task.pull_request && (
                        <a
                            href={task.pull_request.web_url ?? '#'}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 hover:text-foreground hover:underline"
                        >
                            <GitPullRequest className="size-3" />#
                            {task.pull_request.number}
                        </a>
                    )}

                    {task.estimated_hours !== null && (
                        <span>{task.estimated_hours}h estimated</span>
                    )}
                </div>

                <TaskHistory task={task} onFilterRelated={onFilterRelated} />
            </CardContent>
        </Card>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function TaskBoard({
    tasks,
    summary,
    pagination,
    filters,
    types,
    statuses,
    periods,
    authors,
    repositories,
}: Props) {
    const [search, setSearch] = useState(filters.search);

    /** Push a filter change, dropping page so results start from the first page. */
    function apply(changes: Record<string, string | boolean | undefined>) {
        router.get(
            '/tasks',
            {
                ...filters,
                rework: filters.rework ? 1 : undefined,
                page: undefined,
                ...changes,
            },
            { preserveState: true, replace: true, preserveScroll: true },
        );
    }

    /** Move to a page, keeping every filter and returning to the top of the list. */
    function goToPage(page: number) {
        if (
            page < 1 ||
            page > pagination.last_page ||
            page === pagination.current_page
        ) {
            return;
        }

        router.get(
            '/tasks',
            {
                ...filters,
                rework: filters.rework ? 1 : undefined,
                page: page > 1 ? page : undefined,
            },
            { preserveState: true, replace: true },
        );
    }

    function value(next: string) {
        return next === ALL ? undefined : next;
    }

    // Debounced search, so typing does not fire a request per keystroke.
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(
            () => apply({ search: search || undefined }),
            350,
        );

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    function submitSearch(e: FormEvent) {
        e.preventDefault();
        apply({ search: search || undefined });
    }

    const hasActiveFilters =
        Boolean(
            filters.type ||
            filters.status ||
            filters.author ||
            filters.repo_id ||
            filters.related_to,
        ) ||
        filters.rework ||
        filters.period !== 'all';

    return (
        <>
            <Head title="Tasks" />

            <div className="space-y-6 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">Tasks</h1>
                    <p className="text-sm text-muted-foreground">
                        Every unit of work identified from a pull request
                        review, with what came back to it afterwards.
                    </p>
                </div>

                {/* Summary */}
                <div className="grid gap-3 sm:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">
                                Tasks
                            </p>
                            <p className="text-xl font-semibold">
                                {summary.total}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">
                                First time right
                            </p>
                            <p className="text-xl font-semibold">
                                {summary.first_time_right_rate === null
                                    ? '-'
                                    : `${summary.first_time_right_rate}%`}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">
                                Came back
                            </p>
                            <p className="text-xl font-semibold">
                                {summary.reworked}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <p className="text-xs text-muted-foreground">
                                Estimated effort
                            </p>
                            <p className="text-xl font-semibold">
                                {summary.estimated_hours}h
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters - search, the dropdowns and the toggles all in one bar. */}
                <Card>
                    <CardContent className="space-y-3 p-3">
                        <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                            <form
                                onSubmit={submitSearch}
                                className="relative xl:w-72 xl:shrink-0"
                            >
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    className="pl-9"
                                    placeholder="Search tasks by title, description or issue key…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                                {search && (
                                    <button
                                        type="button"
                                        className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        onClick={() => setSearch('')}
                                        aria-label="Clear search"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </form>

                            <div className="grid flex-1 gap-2 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-5">
                                <Select
                                    value={filters.period}
                                    onValueChange={(v) => apply({ period: v })}
                                >
                                    <SelectTrigger aria-label="Period">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {periods.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={filters.type || ALL}
                                    onValueChange={(v) =>
                                        apply({ type: value(v) })
                                    }
                                >
                                    <SelectTrigger aria-label="Type">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            All types
                                        </SelectItem>
                                        {types.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={filters.status || ALL}
                                    onValueChange={(v) =>
                                        apply({ status: value(v) })
                                    }
                                >
                                    <SelectTrigger aria-label="Status">
                                        <SelectValue placeholder="Any status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            Any status
                                        </SelectItem>
                                        {statuses.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={filters.author || ALL}
                                    onValueChange={(v) =>
                                        apply({ author: value(v) })
                                    }
                                >
                                    <SelectTrigger aria-label="Developer">
                                        <SelectValue placeholder="All developers" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            All developers
                                        </SelectItem>
                                        {authors.map((option) => (
                                            <SelectItem
                                                key={option.author_login}
                                                value={option.author_login}
                                            >
                                                {option.author_name ??
                                                    option.author_login}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={filters.repo_id || ALL}
                                    onValueChange={(v) =>
                                        apply({ repo_id: value(v) })
                                    }
                                >
                                    <SelectTrigger aria-label="Repository">
                                        <SelectValue placeholder="All repositories" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>
                                            All repositories
                                        </SelectItem>
                                        {repositories.map((option) => (
                                            <SelectItem
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.full_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={
                                            filters.rework
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            apply({
                                                rework: filters.rework
                                                    ? undefined
                                                    : true,
                                            })
                                        }
                                    >
                                        <AlertTriangle className="mr-1.5 size-3.5" />
                                        Came back only
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    Narrows the board to work that came back - a
                                    task something later fixed, revised or
                                    reverted after it was delivered.
                                </TooltipContent>
                            </Tooltip>

                            {filters.related_to && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        apply({ related_to: undefined })
                                    }
                                >
                                    <Link2 className="mr-1.5 size-3.5" />
                                    Linked tasks
                                    <X className="ml-1.5 size-3.5" />
                                </Button>
                            )}

                            {hasActiveFilters && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.get(
                                            '/tasks',
                                            {},
                                            {
                                                replace: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Clear filters
                                </Button>
                            )}
                        </div>

                        {/* Spelled out under the row, so the toggle still explains itself
                            on touch devices where the tooltip never opens. */}
                        <p className="text-xs text-muted-foreground">
                            {filters.rework
                                ? 'Showing only work that came back - tasks something later fixed, revised or reverted after delivery.'
                                : 'Came back only narrows the board to work something later fixed, revised or reverted after delivery.'}
                        </p>
                    </CardContent>
                </Card>

                {/* Results */}
                {tasks.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center">
                        <ListChecks className="mb-3 size-10 text-muted-foreground/40" />
                        <p className="text-sm font-medium">
                            No tasks match these filters
                        </p>
                        <p className="mt-1 max-w-md text-xs text-muted-foreground">
                            Tasks are identified when a pull request is
                            reviewed. Newly reviewed pull requests will appear
                            here.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {tasks.map((task) => (
                            <TaskRow
                                key={task.id}
                                task={task}
                                onFilterRelated={(id) =>
                                    apply({ related_to: id })
                                }
                            />
                        ))}
                    </div>
                )}

                {/* Pagination */}
                <Pagination pagination={pagination} onNavigate={goToPage} />
            </div>
        </>
    );
}
