import { AlertTriangle, Link2, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
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
import { cn } from '@/lib/utils';
import { FeatureFilterChip, ViewToggle } from './feature-table';
import { ProjectSelect } from './project-filter';
import type { ProjectOption, RepositoryOption } from './project-filter';

export type Option = { value: string; label: string };

export type Filters = {
    search: string;
    type: string;
    status: string;
    author: string;
    repo_id: string;
    project: string;
    period: string;
    rework: boolean;
    related_to: string;
    view: 'features' | 'list';
    feature: string;
};

type Changes = Record<string, string | boolean | undefined>;

/** Radix Select cannot hold an empty value, so "no filter" needs a sentinel. */
const ALL = 'all';

function value(next: string) {
    return next === ALL ? undefined : next;
}

/** How many filters differ from their defaults; the view is not a filter. */
export function activeFilterCount(filters: Filters): number {
    return [
        filters.search,
        filters.type,
        filters.status,
        filters.author,
        filters.repo_id,
        filters.project,
        filters.related_to,
        filters.view === 'list' ? filters.feature : '',
        filters.rework,
        filters.period !== 'all',
    ].filter(Boolean).length;
}

const TRIGGER = 'h-8 max-w-full min-w-0 sm:max-w-[13rem]';

/** "Came back only" as a toggle chip; the explanation lives in its tooltip. */
function CameBackChip({
    active,
    onToggle,
}: {
    active: boolean;
    onToggle: () => void;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    aria-pressed={active}
                    onClick={onToggle}
                    className={cn(
                        'h-8',
                        active &&
                            'border-amber-500/40 bg-amber-500/15 text-amber-700 hover:bg-amber-500/25 hover:text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-400 dark:hover:bg-amber-500/25 dark:hover:text-amber-300',
                    )}
                >
                    <AlertTriangle className="size-3.5" />
                    Came back only
                    {active && <X className="size-3.5" aria-hidden />}
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {active
                    ? 'Showing only work that came back - click to show everything.'
                    : 'Only work that came back: tasks something later fixed, revised or reverted after delivery.'}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * The board's one toolbar: the view switch and search on top, then every filter
 * in a single wrapping row, with the active filters clearable in one click.
 */
export function TaskToolbar({
    filters,
    types,
    statuses,
    periods,
    authors,
    repositories,
    projects,
    withoutProject,
    featureName,
    onApply,
    onProject,
    onClear,
}: {
    filters: Filters;
    types: Option[];
    statuses: Option[];
    periods: Option[];
    authors: { author_login: string; author_name: string | null }[];
    /** The repositories the project filter leaves selectable. */
    repositories: RepositoryOption[];
    projects: ProjectOption[];
    withoutProject: boolean;
    /** The name of the feature the list is narrowed to, when known. */
    featureName: string | null;
    onApply: (changes: Changes) => void;
    onProject: (project: string | undefined) => void;
    /** Reset every filter, keeping the view. */
    onClear: () => void;
}) {
    const [search, setSearch] = useState(filters.search);
    const active = activeFilterCount(filters);

    // Debounced search, so typing does not fire a request per keystroke.
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timer = setTimeout(
            () => onApply({ search: search || undefined }),
            350,
        );

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    function submitSearch(e: FormEvent) {
        e.preventDefault();
        onApply({ search: search || undefined });
    }

    return (
        <div className="space-y-2 rounded-lg border bg-card p-2">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <ViewToggle
                    view={filters.view}
                    onChange={(view) => onApply({ view, feature: undefined })}
                />

                <form
                    onSubmit={submitSearch}
                    role="search"
                    className="relative min-w-0 flex-1"
                >
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        className="h-9 pr-9 pl-9"
                        placeholder="Search title, description or issue key…"
                        aria-label="Search tasks"
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
            </div>

            <div className="flex flex-wrap items-center gap-2 border-t pt-2">
                <Select
                    value={filters.period}
                    onValueChange={(v) => onApply({ period: v })}
                >
                    <SelectTrigger
                        size="sm"
                        className={TRIGGER}
                        aria-label="Period"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {periods.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.type || ALL}
                    onValueChange={(v) => onApply({ type: value(v) })}
                >
                    <SelectTrigger
                        size="sm"
                        className={TRIGGER}
                        aria-label="Type"
                    >
                        <SelectValue placeholder="All types" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All types</SelectItem>
                        {types.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.status || ALL}
                    onValueChange={(v) => onApply({ status: value(v) })}
                >
                    <SelectTrigger
                        size="sm"
                        className={TRIGGER}
                        aria-label="Status"
                    >
                        <SelectValue placeholder="Any status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>Any status</SelectItem>
                        {statuses.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.author || ALL}
                    onValueChange={(v) => onApply({ author: value(v) })}
                >
                    <SelectTrigger
                        size="sm"
                        className={TRIGGER}
                        aria-label="Developer"
                    >
                        <SelectValue placeholder="All developers" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All developers</SelectItem>
                        {authors.map((option) => (
                            <SelectItem
                                key={option.author_login}
                                value={option.author_login}
                            >
                                {option.author_name ?? option.author_login}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <ProjectSelect
                    value={filters.project}
                    projects={projects}
                    withoutProject={withoutProject}
                    onChange={onProject}
                    className={TRIGGER}
                />

                <Select
                    value={filters.repo_id || ALL}
                    onValueChange={(v) => onApply({ repo_id: value(v) })}
                >
                    <SelectTrigger
                        size="sm"
                        className={TRIGGER}
                        aria-label="Repository"
                    >
                        <SelectValue placeholder="All repositories" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>All repositories</SelectItem>
                        {repositories.map((option) => (
                            <SelectItem key={option.id} value={option.id}>
                                {option.full_name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <CameBackChip
                    active={filters.rework}
                    onToggle={() =>
                        onApply({ rework: filters.rework ? undefined : true })
                    }
                />

                {filters.related_to && (
                    <Button
                        type="button"
                        size="sm"
                        variant="secondary"
                        className="h-8"
                        onClick={() => onApply({ related_to: undefined })}
                    >
                        <Link2 className="size-3.5" />
                        Linked tasks
                        <X className="size-3.5" />
                    </Button>
                )}

                {filters.view === 'list' && filters.feature && (
                    <FeatureFilterChip
                        feature={filters.feature}
                        name={featureName}
                        onClear={() => onApply({ feature: undefined })}
                    />
                )}

                {active > 0 && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-8 text-muted-foreground sm:ml-auto"
                        onClick={onClear}
                    >
                        <X className="size-3.5" />
                        Clear filters
                        <span className="rounded bg-muted px-1 text-[11px] text-foreground tabular-nums">
                            {active}
                        </span>
                    </Button>
                )}
            </div>
        </div>
    );
}
