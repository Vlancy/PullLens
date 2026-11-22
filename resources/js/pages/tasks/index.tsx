import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    ExternalLink,
    GitPullRequest,
    Layers,
    Link2,
    ListChecks,
    MoreHorizontal,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { BranchChip } from './branch-chip';
import type { FeatureOption } from './feature-actions';
import { FeatureTable } from './feature-table';
import type { FeaturePage } from './feature-table';
import { repositoriesInProject } from './project-filter';
import type {
    FeatureGroup,
    ProjectOption,
    RepositoryOption,
} from './project-filter';
import { StatTiles } from './stat-tiles';
import type { TaskSummary } from './stat-tiles';
import { TaskToolbar } from './task-filters';
import type { Filters, Option } from './task-filters';

// ─── Types ────────────────────────────────────────────────────────────────────

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
    scope_key: string | null;
    feature: { id: string; name: string } | null;
    feature_source: string | null;
    pull_request: {
        number: number;
        title: string;
        web_url: string | null;
        state: string | null;
        source_branch: string | null;
        target_branch: string | null;
        merged_at: string | null;
        /** Every branch the merged work reached, the last being where it lives now. */
        path: string[];
        promoted_by: { number: number | null; url: string | null } | null;
    } | null;
    links: LinkedTask[];
    inbound_links: LinkedTask[];
};

type Props = {
    tasks: Task[];
    summary: TaskSummary;
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
        /** The feature filter these tasks were loaded for, if any. */
        feature: string | null;
    };
    filters: Filters;
    types: Option[];
    statuses: Option[];
    periods: Option[];
    authors: { author_login: string; author_name: string | null }[];
    repositories: RepositoryOption[];
    projects: ProjectOption[];
    has_repositories_without_project: boolean;
    features?: FeaturePage;
    feature_groups?: FeatureGroup[];
    can_manage_features: boolean;
    feature_options: FeatureOption[];
};

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
    actions,
}: {
    task: Task;
    onFilterRelated: (id: string) => void;
    /** Extra controls for the row, such as moving it to another feature. */
    actions?: ReactNode;
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

                    {task.pull_request && (
                        <BranchChip
                            compact
                            linked={false}
                            branch={{
                                source: task.pull_request.source_branch,
                                target: task.pull_request.target_branch,
                                state: task.pull_request.state,
                                number: task.pull_request.number,
                                url: task.pull_request.web_url,
                                path: task.pull_request.path,
                                promoted_by: task.pull_request.promoted_by,
                            }}
                        />
                    )}

                    {task.estimated_hours !== null && (
                        <span>{task.estimated_hours}h estimated</span>
                    )}

                    {task.feature && (
                        <span className="inline-flex items-center gap-1">
                            <Layers className="size-3" />
                            {task.feature.name}
                        </span>
                    )}

                    {actions && <span className="ml-auto">{actions}</span>}
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
    projects,
    has_repositories_without_project: withoutProject,
    features,
    feature_groups: featureGroups,
    can_manage_features: canManage,
    feature_options: featureOptions,
}: Props) {
    /** Push a filter change, dropping page so results start from the first page. */
    function apply(changes: Record<string, string | boolean | undefined>) {
        router.get(
            '/tasks',
            {
                ...filters,
                rework: filters.rework ? 1 : undefined,
                page: undefined,
                // In the features view a filter change collapses the open row.
                feature:
                    filters.view === 'list'
                        ? filters.feature || undefined
                        : undefined,
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

    /** Reset every filter, keeping the view; a full visit so the search box resets too. */
    function clearFilters() {
        router.get(
            '/tasks',
            { view: filters.view === 'list' ? 'list' : undefined },
            { replace: true, preserveScroll: true },
        );
    }

    const projectRepositories = repositoriesInProject(
        repositories,
        filters.project,
    );

    /** Switch project, dropping a repository the new project does not hold. */
    function applyProject(project: string | undefined) {
        const keepsRepository = repositoriesInProject(
            repositories,
            project ?? '',
        ).some((repository) => repository.id === filters.repo_id);

        apply({
            project,
            repo_id: keepsRepository ? filters.repo_id : undefined,
            feature: undefined,
            features_page: undefined,
        });
    }

    return (
        <>
            <Head title="Tasks" />

            <div className="space-y-4 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">Tasks</h1>
                    <p className="text-sm text-muted-foreground">
                        Every unit of work identified from a pull request
                        review, with what came back to it afterwards.
                    </p>
                </div>

                <StatTiles
                    summary={summary}
                    reworkOnly={filters.rework}
                    onToggleRework={() =>
                        apply({ rework: filters.rework ? undefined : '1' })
                    }
                />

                <TaskToolbar
                    filters={filters}
                    types={types}
                    statuses={statuses}
                    periods={periods}
                    authors={authors}
                    repositories={projectRepositories}
                    projects={projects}
                    withoutProject={withoutProject}
                    featureName={tasks[0]?.feature?.name ?? null}
                    onApply={apply}
                    onProject={applyProject}
                    onClear={clearFilters}
                />

                {/* Results */}
                {features ? (
                    <FeatureTable
                        features={features}
                        tasks={tasks}
                        tasksTotal={pagination.total}
                        loadedFeature={pagination.feature}
                        query={{
                            ...filters,
                            rework: filters.rework ? 1 : undefined,
                        }}
                        renderTask={(task, actions) => (
                            <TaskRow
                                key={task.id}
                                task={task}
                                // A linked fix may sit in another feature, so the
                                // pivot drops the feature filter.
                                onFilterRelated={(id) =>
                                    apply({
                                        view: 'list',
                                        related_to: id,
                                        feature: undefined,
                                    })
                                }
                                actions={actions}
                            />
                        )}
                        onShowAll={(id) => apply({ view: 'list', feature: id })}
                        canManage={canManage}
                        options={featureOptions}
                        groups={featureGroups ?? []}
                        projectFilter={filters.project}
                        onSelectProject={applyProject}
                    />
                ) : tasks.length === 0 ? (
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
                                    apply({
                                        related_to: id,
                                        feature: undefined,
                                    })
                                }
                            />
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {!features && (
                    <Pagination pagination={pagination} onNavigate={goToPage} />
                )}
            </div>
        </>
    );
}
