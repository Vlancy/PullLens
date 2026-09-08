import { Head, router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Clock,
    ExternalLink,
    GitCommitHorizontal,
    GitPullRequest,
    ListChecks,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { ReportsNav } from '@/components/reports-nav';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

// ─── Types ────────────────────────────────────────────────────────────────────

type Option = { value: string; label: string };

type Author = { author_login: string; author_name: string | null };

type Repository = { id: string; full_name: string };

type Task = {
    id: string;
    title: string;
    type: string | null;
    type_label: string | null;
    description: string | null;
    estimated_hours: number | null;
    files: string[];
    delivered_at: string | null;
    commits: number;
    repository: { id: string; full_name: string } | null;
    pull_request: {
        number: number;
        title: string;
        web_url: string | null;
        state: string | null;
    } | null;
};

type Developer = {
    author_login: string;
    author_name: string | null;
    author_avatar_url: string | null;
    total_tasks: number;
    estimated_hours: number;
    pull_requests: number;
    commits: number;
    by_type: Record<string, number>;
    tasks: Task[];
};

type Props = {
    developers: Developer[];
    stats: {
        total_tasks: number;
        developers: number;
        estimated_hours: number;
        by_type: Record<string, number>;
    };
    period: string;
    periods: Option[];
    author: string | null;
    authors: Author[];
    repo_id: string | null;
    repositories: Repository[];
    type: string | null;
    types: Option[];
};

/** Sentinel for "no filter"; Radix Select cannot hold an empty string value. */
const ALL = 'all';

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Tailwind classes per task type, so a type reads the same everywhere on the page. */
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

function typeClass(type: string | null): string {
    return (type && TYPE_STYLES[type]) || TYPE_STYLES.chore;
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
    if (!iso) {
        return '-';
    }

    return new Date(iso).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

function formatHours(hours: number | null): string {
    if (hours === null || Number.isNaN(hours)) {
        return '-';
    }

    return `${Math.round(hours * 10) / 10}h`;
}

// ─── Stat tile ────────────────────────────────────────────────────────────────

function StatCard({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string | number;
    icon: typeof ListChecks;
}) {
    return (
        <Card>
            <CardContent className="flex items-center gap-3 p-4">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                    <Icon className="size-4 text-muted-foreground" />
                </div>
                <div className="min-w-0">
                    <p className="text-xs text-muted-foreground">{label}</p>
                    <p className="text-xl font-semibold">{value}</p>
                </div>
            </CardContent>
        </Card>
    );
}

// ─── Per-developer block ──────────────────────────────────────────────────────

function DeveloperTasks({
    developer,
    types,
}: {
    developer: Developer;
    types: Option[];
}) {
    const [open, setOpen] = useState(true);
    const name = developer.author_name ?? developer.author_login;

    return (
        <Card>
            <CardContent className="p-0">
                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    className="flex w-full items-center gap-3 p-4 text-left transition-colors hover:bg-muted/40"
                    aria-expanded={open}
                >
                    {open ? (
                        <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
                    ) : (
                        <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                    )}

                    <Avatar className="size-8 shrink-0">
                        <AvatarImage
                            src={developer.author_avatar_url ?? undefined}
                            alt={name}
                        />
                        <AvatarFallback className="text-xs">
                            {initials(name)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{name}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            {developer.total_tasks} task
                            {developer.total_tasks === 1 ? '' : 's'}
                            {' · '}
                            {developer.pull_requests} PR
                            {developer.pull_requests === 1 ? '' : 's'}
                            {' · '}
                            {developer.commits} commit
                            {developer.commits === 1 ? '' : 's'}
                            {' · '}
                            {formatHours(developer.estimated_hours)} estimated
                        </p>
                    </div>

                    <div className="hidden shrink-0 flex-wrap justify-end gap-1 sm:flex">
                        {types
                            .filter(
                                (type) =>
                                    (developer.by_type[type.value] ?? 0) > 0,
                            )
                            .map((type) => (
                                <span
                                    key={type.value}
                                    className={`rounded px-1.5 py-0.5 text-xs font-medium ${typeClass(type.value)}`}
                                >
                                    {developer.by_type[type.value]} {type.label}
                                </span>
                            ))}
                    </div>
                </button>

                {open && (
                    <div className="border-t">
                        {developer.tasks.map((task) => (
                            <div
                                key={task.id}
                                className="flex flex-col gap-1.5 border-b px-4 py-3 last:border-b-0 sm:flex-row sm:items-start sm:gap-4"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={`rounded px-1.5 py-0.5 text-xs font-medium ${typeClass(task.type)}`}
                                        >
                                            {task.type_label ?? 'Chore'}
                                        </span>
                                        <span className="font-medium">
                                            {task.title}
                                        </span>
                                    </div>

                                    {task.description && (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {task.description}
                                        </p>
                                    )}

                                    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                        {task.repository && (
                                            <span className="font-mono">
                                                {task.repository.full_name}
                                            </span>
                                        )}

                                        {task.pull_request && (
                                            <a
                                                href={
                                                    task.pull_request.web_url ??
                                                    '#'
                                                }
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 hover:text-foreground hover:underline"
                                            >
                                                <GitPullRequest className="size-3" />
                                                #{task.pull_request.number}
                                                <ExternalLink className="size-3" />
                                            </a>
                                        )}

                                        <span className="inline-flex items-center gap-1">
                                            <GitCommitHorizontal className="size-3" />
                                            {task.commits} commit
                                            {task.commits === 1 ? '' : 's'}
                                        </span>

                                        <span>
                                            {formatDate(task.delivered_at)}
                                        </span>
                                    </div>
                                </div>

                                <Badge
                                    variant="outline"
                                    className="shrink-0 self-start"
                                >
                                    {formatHours(task.estimated_hours)}
                                </Badge>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Tasks({
    developers,
    stats,
    period,
    periods,
    author,
    authors,
    repo_id,
    repositories,
    type,
    types,
}: Props) {
    /** Push one filter change, preserving the others. */
    function apply(changes: Record<string, string | undefined>) {
        router.get(
            '/reports/tasks',
            {
                period,
                author: author ?? undefined,
                repo_id: repo_id ?? undefined,
                type: type ?? undefined,
                ...changes,
            },
            { preserveState: true, replace: true },
        );
    }

    /** Radix cannot hold an empty value, so ALL is mapped back to "no filter". */
    function filterValue(value: string | undefined) {
        return value === ALL ? undefined : value;
    }

    return (
        <>
            <Head title="Tasks delivered" />

            <div className="space-y-6 p-4 md:p-6">
                <ReportsNav active="/reports/tasks" />

                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">Tasks delivered</h1>
                    <p className="text-sm text-muted-foreground">
                        What each developer shipped, extracted from the review
                        of every merged pull request. Only merged work is
                        counted.
                    </p>
                </div>

                {/* Filters */}
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <Select
                        value={period}
                        onValueChange={(value) => apply({ period: value })}
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
                        value={author ?? ALL}
                        onValueChange={(value) =>
                            apply({ author: filterValue(value) })
                        }
                    >
                        <SelectTrigger aria-label="Developer">
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

                    <Select
                        value={repo_id ?? ALL}
                        onValueChange={(value) =>
                            apply({ repo_id: filterValue(value) })
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
                                <SelectItem key={option.id} value={option.id}>
                                    {option.full_name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={type ?? ALL}
                        onValueChange={(value) =>
                            apply({ type: filterValue(value) })
                        }
                    >
                        <SelectTrigger aria-label="Task type">
                            <SelectValue placeholder="All types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>All types</SelectItem>
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
                </div>

                {/* Totals */}
                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Tasks delivered"
                        value={stats.total_tasks}
                        icon={ListChecks}
                    />
                    <StatCard
                        label="Developers"
                        value={stats.developers}
                        icon={Users}
                    />
                    <StatCard
                        label="Estimated effort"
                        value={formatHours(stats.estimated_hours)}
                        icon={Clock}
                    />
                </div>

                {/* Type breakdown */}
                {stats.total_tasks > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {types
                            .filter(
                                (option) =>
                                    (stats.by_type[option.value] ?? 0) > 0,
                            )
                            .map((option) => (
                                <span
                                    key={option.value}
                                    className={`rounded px-2 py-1 text-xs font-medium ${typeClass(option.value)}`}
                                >
                                    {stats.by_type[option.value]} {option.label}
                                </span>
                            ))}
                    </div>
                )}

                {/* Per developer */}
                {developers.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center">
                        <ListChecks className="mb-3 size-10 text-muted-foreground/40" />
                        <p className="text-sm font-medium">
                            No tasks in this period
                        </p>
                        <p className="mt-1 max-w-md text-xs text-muted-foreground">
                            Tasks are extracted when a pull request is reviewed,
                            and counted once it merges. Newly reviewed pull
                            requests will appear here.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {developers.map((developer) => (
                            <DeveloperTasks
                                key={developer.author_login}
                                developer={developer}
                                types={types}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
