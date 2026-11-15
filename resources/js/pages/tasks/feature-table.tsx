import { router } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, Layers } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { FeatureMenu } from './feature-actions';
import type { FeatureOption } from './feature-actions';

// ─── Types ────────────────────────────────────────────────────────────────────

export type FeatureRow = {
    /** A feature id, or `ungrouped` for tasks without a feature. */
    id: string;
    name: string;
    description: string | null;
    scope_key: string | null;
    scope_label: string | null;
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

// ─── Expansion and view toggle ────────────────────────────────────────────────

/**
 * Which feature row is open, and which row the page's `tasks` prop was loaded for,
 * so a row never shows the tasks of the previous one while its own are loading.
 */
export function useFeatureExpansion(initial: string | null) {
    const [expanded, setExpanded] = useState<string | null>(initial);
    const [loadedFor, setLoadedFor] = useState<string | null>(initial);

    /** Open a row, reloading only the task list for it; close it if open. */
    function toggle(id: string) {
        if (expanded === id) {
            setExpanded(null);

            return;
        }

        setExpanded(id);
        router.reload({
            only: ['tasks', 'pagination'],
            data: { feature: id, page: undefined },
            onSuccess: () => setLoadedFor(id),
        });
    }

    function reset() {
        setExpanded(null);
        setLoadedFor(null);
    }

    return {
        expanded,
        isLoaded: (id: string) => loadedFor === id,
        toggle,
        reset,
    };
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

// ─── Table ────────────────────────────────────────────────────────────────────

/**
 * One row per feature with its counts; a row expands to its tasks, which the
 * page loads and renders through `renderTasks`.
 */
export function FeatureTable({
    features,
    expandedId,
    onToggle,
    renderTasks,
    canManage,
    options,
    onNavigate,
}: {
    features: FeaturePage;
    expandedId: string | null;
    onToggle: (id: string) => void;
    renderTasks: (row: FeatureRow) => ReactNode;
    canManage: boolean;
    options: FeatureOption[];
    onNavigate: (page: number) => void;
}) {
    const { data, pagination } = features;

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
            {data.map((row) => {
                const expanded = row.id === expandedId;
                const ungrouped = row.id === UNGROUPED;

                return (
                    <Card key={row.id} className="gap-0 py-0">
                        <div className="flex items-center gap-2 p-3">
                            <button
                                type="button"
                                onClick={() => onToggle(row.id)}
                                aria-expanded={expanded}
                                className="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2 text-left"
                            >
                                <span className="flex min-w-0 flex-1 basis-56 items-center gap-2">
                                    {expanded ? (
                                        <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                                    ) : (
                                        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                    )}
                                    <span className="min-w-0">
                                        <span
                                            className={`block truncate font-medium ${ungrouped ? 'text-muted-foreground italic' : ''}`}
                                        >
                                            {row.name}
                                        </span>
                                        <span className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                            {row.scope_label && (
                                                <span className="truncate">
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
                                            <span>
                                                Last activity{' '}
                                                {formatDate(
                                                    row.last_activity_at,
                                                )}
                                            </span>
                                        </span>
                                    </span>
                                </span>

                                <Contributors people={row.contributors} />

                                <span className="flex items-center gap-2">
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
                                </span>
                            </button>

                            {canManage && !ungrouped && (
                                <FeatureMenu feature={row} options={options} />
                            )}
                        </div>

                        {expanded && (
                            <div className="space-y-3 border-t bg-muted/30 p-3">
                                {renderTasks(row)}
                            </div>
                        )}
                    </Card>
                );
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
