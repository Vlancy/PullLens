import { FolderKanban } from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

// ─── Types ────────────────────────────────────────────────────────────────────

export type ProjectOption = { id: string; name: string };

export type RepositoryOption = {
    id: string;
    full_name: string;
    project_id: string | null;
};

/** One project or repository scope with its totals across every feature page. */
export type FeatureGroup = {
    key: string;
    label: string | null;
    kind: 'project' | 'repository';
    features: number;
    tasks: number;
    shipped: number;
    came_back: number;
};

/** The `project` filter value for repositories that belong to no project. */
export const NO_PROJECT = 'none';

/** Radix Select cannot hold an empty value, so "no filter" needs a sentinel. */
const ALL = 'all';

/** The repositories a project filter leaves selectable. */
export function repositoriesInProject(
    repositories: RepositoryOption[],
    project: string,
): RepositoryOption[] {
    if (!project) {
        return repositories;
    }

    return repositories.filter((repository) =>
        project === NO_PROJECT
            ? repository.project_id === null
            : repository.project_id === project,
    );
}

// ─── Project select ───────────────────────────────────────────────────────────

/**
 * The filters bar's project select: every project, or one, or repositories
 * outside any project. `onChange` receives the filter value, undefined for all.
 */
export function ProjectSelect({
    value,
    projects,
    withoutProject,
    onChange,
}: {
    value: string;
    projects: ProjectOption[];
    /** Whether the viewer can see repositories that belong to no project. */
    withoutProject: boolean;
    onChange: (project: string | undefined) => void;
}) {
    if (projects.length === 0 && !value) {
        return null;
    }

    return (
        <Select
            value={value || ALL}
            onValueChange={(v) => onChange(v === ALL ? undefined : v)}
        >
            <SelectTrigger aria-label="Project">
                <SelectValue placeholder="All projects" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>All projects</SelectItem>
                {projects.map((project) => (
                    <SelectItem key={project.id} value={project.id}>
                        {project.name}
                    </SelectItem>
                ))}
                {withoutProject && (
                    <SelectItem value={NO_PROJECT}>No project</SelectItem>
                )}
            </SelectContent>
        </Select>
    );
}

// ─── Projects strip ───────────────────────────────────────────────────────────

/**
 * A compact row of the projects in the current results, above the feature rows;
 * clicking one narrows the board to it.
 */
export function ProjectStrip({
    groups,
    onSelect,
}: {
    groups: FeatureGroup[];
    onSelect: (projectId: string) => void;
}) {
    const projects = groups.filter((group) => group.kind === 'project');

    if (projects.length === 0) {
        return null;
    }

    return (
        <nav aria-label="Projects" className="flex gap-2 overflow-x-auto pb-1">
            {projects.map((group) => (
                <button
                    key={group.key}
                    type="button"
                    onClick={() => onSelect(group.key.replace(/^project:/, ''))}
                    className="flex shrink-0 items-center gap-2 rounded-lg border bg-card px-3 py-2 text-left text-sm transition-colors hover:bg-muted"
                >
                    <FolderKanban className="size-4 text-muted-foreground" />
                    <span className="font-medium">{group.label}</span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {group.features} feature
                        {group.features === 1 ? '' : 's'} · {group.tasks} task
                        {group.tasks === 1 ? '' : 's'}
                    </span>
                </button>
            ))}
        </nav>
    );
}
