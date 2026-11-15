import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FolderGit2,
    FolderKanban,
    Layers,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { BranchChip } from './branch-chip';
import type { BranchInfo } from './branch-chip';
import { FeatureMenu, MoveTaskButton } from './feature-actions';
import type { FeatureOption, MovableTask } from './feature-actions';
import { ProjectStrip } from './project-filter';
import type { FeatureGroup } from './project-filter';

// ─── Types ────────────────────────────────────────────────────────────────────

export type FeatureRow = {
    /** A feature id, or `ungrouped` for tasks without a feature. */
    id: string;
    name: string;
    description: string | null;
    scope_key: string | null;
    scope_label: string | null;
    /** The row's group: its scope key, or null for the Ungrouped row. */
    group_key: string | null;
    group_label: string | null;
    group_kind: 'project' | 'repository' | null;
    source: string | null;
    tasks: number;
    shipped: number;
    came_back: number;
    contributors: {
        login: string;
        name: string | null;
        avatar_url: string | null;
    }[];
    last_activity_at: string | null;
    /** The branches of the row's most recent pull request; null for Ungrouped. */
    last_branch: BranchInfo | null;
};

export type FeaturePage = {
    data: FeatureRow[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

export const UNGROUPED = 'ungrouped';

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
        : '-';
}

function Count({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: string;
}) {
    return (
        <div className="min-w-16 text-right">
            <p className={`text-sm font-semibold tabular-nums ${tone ?? ''}`}>
                {value}
            </p>
            <p className="text-[11px] text-muted-foreground">{label}</p>
        </div>
    );
}

function Contributors({ people }: { people: FeatureRow['contributors'] }) {
    if (people.length === 0) {
        return null;
    }

    return (
        <div className="flex -space-x-1.5">
            {people.map((person) => {
                const name = person.name ?? person.login;

                return (
                    <Avatar
                        key={person.login}
                        className="size-6 border-2 border-background"
                        title={name}
                    >
                        <AvatarImage
                            src={person.avatar_url ?? undefined}
                            alt={name}
                        />
                        <AvatarFallback className="text-[9px]">
                            {initials(name)}
                        </AvatarFallback>
                    </Avatar>
                );
            })}
        </div>
    );
}

// ─── View toggle and feature filter chip ──────────────────────────────────────

/** The list view's chip for an active feature filter; clicking it clears the filter. */
export function FeatureFilterChip({
    feature,
    name,
    onClear,
}: {
    feature: string;
    name: string | null;
    onClear: () => void;
}) {
    return (
        <Button type="button" size="sm" variant="secondary" onClick={onClear}>
            <Layers className="mr-1.5 size-3.5" />
            {feature === UNGROUPED
                ? 'Ungrouped tasks'
                : (name ?? 'One feature')}
            <X className="ml-1.5 size-3.5" />
        </Button>
    );
}

export function ViewToggle({
    view,
    onChange,
}: {
    view: 'features' | 'list';
    onChange: (view: 'features' | 'list') => void;
}) {
    const views = [
        ['features', 'Features'],
        ['list', 'All tasks'],
    ] as const;

    return (
        <div
            role="group"
            aria-label="Board view"
            className="inline-flex rounded-lg border p-0.5"
        >
            {views.map(([value, label]) => (
                <Button
                    key={value}
                    type="button"
                    size="sm"
                    variant={view === value ? 'default' : 'ghost'}
                    aria-pressed={view === value}
                    onClick={() => view !== value && onChange(value)}
                >
                    {label}
                </Button>
            ))}
        </div>
    );
}

// ─── Group header ─────────────────────────────────────────────────────────────

function plural(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

/** The heading above a project's or repository's features; toggles its rows. */
function GroupHeader({
    label,
    kind,
    stats,
    continued,
    collapsed,
    onToggle,
}: {
    label: string;
    kind: FeatureRow['group_kind'];
    stats: GroupStats;
    continued: boolean;
    collapsed: boolean;
    onToggle: () => void;
}) {
    const Icon =
        kind === 'project'
            ? FolderKanban
            : kind === 'repository'
              ? FolderGit2
              : Layers;

    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={!collapsed}
            className="mt-2 flex w-full flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-transparent bg-muted/50 px-2.5 py-2 text-left transition-colors outline-none first:mt-0 hover:border-border focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <span className="flex min-w-0 items-center gap-2">
                {collapsed ? (
                    <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                ) : (
                    <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                )}
                <Icon className="size-4 shrink-0 text-foreground/70" />
                <span className="truncate text-sm font-semibold">{label}</span>
                {continued && (
                    <span className="text-xs text-muted-foreground">
                        (continued)
                    </span>
                )}
            </span>
            <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground tabular-nums sm:ml-auto">
                {stats.features !== null && (
                    <span>{plural(stats.features, 'feature')}</span>
                )}
                <span>{plural(stats.tasks, 'task')}</span>
                <span>{stats.shipped} shipped</span>
                <span
                    className={cn(
                        stats.came_back > 0 &&
                            'font-medium text-amber-700 dark:text-amber-400',
                    )}
                >
                    {stats.came_back} came back
                </span>
            </span>
        </button>
    );
}

type GroupStats = {
    features: number | null;
    tasks: number;
    shipped: number;
    came_back: number;
};

/** The heading's totals, from the group's counts across every page. */
function groupStats(
    group: FeatureGroup | undefined,
    row: FeatureRow,
): GroupStats {
    if (!group) {
        return {
            features: null,
            tasks: row.tasks,
            shipped: row.shipped,
            came_back: row.came_back,
        };
    }

    return group;
}

/**
 * Where each group's first feature sits in the full, paginated ordering, so a
 * page can tell whether a group started on an earlier page.
 */
function groupStarts(groups: FeatureGroup[]): Map<string, number> {
    const starts = new Map<string, number>();
    let offset = 0;

    for (const group of groups) {
        starts.set(group.key, offset);
        offset += group.features;
    }

    return starts;
}

// ─── Table ────────────────────────────────────────────────────────────────────

/**
 * One row per feature with its counts. A row expands to its tasks: the page's
 * task list is reloaded for that feature and rendered through `renderTask`.
 *
 * `loadedFeature` is the feature the server says the loaded tasks belong to, so a
 * row never shows another row's tasks, and a filter change that drops the
 * feature simply collapses the row.
 */
export function FeatureTable<T extends MovableTask>({
    features,
    tasks,
    tasksTotal,
    loadedFeature,
    query,
    renderTask,
    onShowAll,
    canManage,
    options,
    groups,
    projectFilter,
    onSelectProject,
}: {
    features: FeaturePage;
    tasks: T[];
    tasksTotal: number;
    loadedFeature: string | null;
    /** The board's current filters, kept when moving through feature pages. */
    query: Record<string, unknown>;
    renderTask: (task: T, actions: ReactNode) => ReactNode;
    onShowAll: (featureId: string) => void;
    canManage: boolean;
    options: FeatureOption[];
    /** Totals per project or repository across every feature page. */
    groups: FeatureGroup[];
    /** The active project filter, empty for all projects. */
    projectFilter: string;
    onSelectProject: (projectId: string) => void;
}) {
    const { data, pagination } = features;
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const groupsByKey = new Map(groups.map((group) => [group.key, group]));
    const starts = groupStarts(groups);
    const pageOffset = (pagination.current_page - 1) * pagination.per_page;

    function toggleGroup(key: string) {
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });
    }
    const [expanded, setExpanded] = useState<string | null>(loadedFeature);
    const [pending, setPending] = useState<string | null>(null);
    const expandedId =
        expanded !== null &&
        (pending === expanded || loadedFeature === expanded)
            ? expanded
            : null;

    /** Open a row, reloading only the task list for it; close it if open. */
    function onToggle(id: string) {
        if (expandedId === id) {
            setExpanded(null);

            return;
        }

        setExpanded(id);
        setPending(id);
        router.reload({
            only: ['tasks', 'pagination'],
            data: { feature: id, page: undefined },
            onFinish: () => setPending(null),
        });
    }

    /** Move through the feature rows, keeping every filter. */
    function onNavigate(page: number) {
        setExpanded(null);
        router.get(
            '/tasks',
            {
                ...query,
                feature: undefined,
                features_page: page > 1 ? page : undefined,
            },
            { preserveState: true, replace: true },
        );
    }

    function renderTasks(row: FeatureRow) {
        if (loadedFeature !== row.id) {
            return (
                <p className="text-sm text-muted-foreground">Loading tasks…</p>
            );
        }

        return (
            <>
                {tasks.map((task) =>
                    renderTask(
                        task,
                        canManage ? (
                            <MoveTaskButton task={task} options={options} />
                        ) : undefined,
                    ),
                )}
                {tasksTotal > tasks.length && (
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        className="px-0"
                        onClick={() => onShowAll(row.id)}
                    >
                        Showing {tasks.length} of {tasksTotal} - see all in the
                        task list
                    </Button>
                )}
            </>
        );
    }

    if (data.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center">
                <Layers className="mb-3 size-10 text-muted-foreground/40" />
                <p className="text-sm font-medium">
                    No features match these filters
                </p>
                <p className="mt-1 max-w-md text-xs text-muted-foreground">
                    Features group related tasks as pull requests are reviewed.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {!projectFilter && (
                <ProjectStrip groups={groups} onSelect={onSelectProject} />
            )}

            {data.map((row, index) => {
                const expanded = row.id === expandedId;
                const ungrouped = row.id === UNGROUPED;
                const groupKey = row.group_key ?? UNGROUPED;
                const firstOfGroup =
                    index === 0 ||
                    (data[index - 1].group_key ?? UNGROUPED) !== groupKey;
                const group = row.group_key
                    ? groupsByKey.get(row.group_key)
                    : undefined;
                const header = firstOfGroup && (
                    <GroupHeader
                        key={`group-${groupKey}`}
                        label={
                            ungrouped
                                ? 'Without a feature'
                                : (row.group_label ?? 'Unknown scope')
                        }
                        kind={row.group_kind}
                        stats={groupStats(group, row)}
                        continued={
                            row.group_key !== null &&
                            (starts.get(row.group_key) ?? pageOffset) <
                                pageOffset
                        }
                        collapsed={collapsed.has(groupKey)}
                        onToggle={() => toggleGroup(groupKey)}
                    />
                );

                if (collapsed.has(groupKey)) {
                    return header || null;
                }

                return [
                    header,
                    <Card key={row.id} className="gap-0 py-0">
                        {/* The name is the toggle; its overlay stretches over the
                            row so the whole row stays clickable, while the branch
                            link and the menu sit above it. */}
                        <div className="relative flex items-center gap-2 rounded-xl p-3 transition-colors has-[[data-row-toggle]:hover]:bg-muted/30">
                            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2">
                                <div className="flex min-w-0 flex-1 basis-56 items-start gap-2">
                                    {expanded ? (
                                        <ChevronDown className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    ) : (
                                        <ChevronRight className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    )}
                                    <div className="min-w-0 space-y-1">
                                        <button
                                            type="button"
                                            data-row-toggle
                                            onClick={() => onToggle(row.id)}
                                            aria-expanded={expanded}
                                            className={`block max-w-full truncate text-left font-medium outline-none after:absolute after:inset-0 after:rounded-xl after:content-[''] focus-visible:after:ring-[3px] focus-visible:after:ring-ring/50 ${ungrouped ? 'text-muted-foreground italic' : ''}`}
                                        >
                                            {row.name}
                                        </button>
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                            {row.scope_label && (
                                                <span className="max-w-[12rem] truncate">
                                                    {row.scope_label}
                                                </span>
                                            )}
                                            {row.source === 'manual' && (
                                                <Badge
                                                    variant="outline"
                                                    className="h-4 px-1 text-[10px]"
                                                >
                                                    manual
                                                </Badge>
                                            )}
                                            <span className="whitespace-nowrap">
                                                {row.scope_label && (
                                                    <span
                                                        aria-hidden
                                                        className="mr-2 opacity-50"
                                                    >
                                                        ·
                                                    </span>
                                                )}
                                                Last active{' '}
                                                {formatDate(
                                                    row.last_activity_at,
                                                )}
                                            </span>
                                            {row.last_branch && (
                                                <BranchChip
                                                    branch={row.last_branch}
                                                    className="relative z-10"
                                                />
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="relative z-10">
                                    <Contributors people={row.contributors} />
                                </div>

                                <div className="flex items-center gap-2">
                                    <Count label="Tasks" value={row.tasks} />
                                    <Count
                                        label="Shipped"
                                        value={row.shipped}
                                    />
                                    <Count
                                        label="Came back"
                                        value={row.came_back}
                                        tone={
                                            row.came_back > 0
                                                ? 'text-amber-700 dark:text-amber-400'
                                                : undefined
                                        }
                                    />
                                </div>
                            </div>

                            {canManage && !ungrouped && (
                                <div className="relative z-10">
                                    <FeatureMenu
                                        feature={row}
                                        options={options}
                                    />
                                </div>
                            )}
                        </div>

                        {expanded && (
                            <div className="space-y-3 border-t bg-muted/30 p-3">
                                {renderTasks(row)}
                            </div>
                        )}
                    </Card>,
                ];
            })}

            {pagination.last_page > 1 && (
                <nav
                    aria-label="Feature pages"
                    className="flex items-center justify-between gap-3 border-t pt-4"
                >
                    <p className="text-sm text-muted-foreground">
                        Page {pagination.current_page} of {pagination.last_page}
                    </p>
                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.current_page <= 1}
                            onClick={() =>
                                onNavigate(pagination.current_page - 1)
                            }
                            aria-label="Previous page"
                        >
                            <ChevronLeft className="size-4" />
                            <span className="hidden sm:inline">Previous</span>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                pagination.current_page >= pagination.last_page
                            }
                            onClick={() =>
                                onNavigate(pagination.current_page + 1)
                            }
                            aria-label="Next page"
                        >
                            <span className="hidden sm:inline">Next</span>
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                </nav>
            )}
        </div>
    );
}
