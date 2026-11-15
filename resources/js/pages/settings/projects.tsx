import { Head, router, useForm } from '@inertiajs/react';
import {
    ChevronRight,
    FolderKanban,
    FolderPlus,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { RepositoryTagsEditor } from '@/components/repository-tags-editor';
import type {
    RepositoryTag,
    TagKindOption,
} from '@/components/repository-tags-editor';
import { FeatureGroupingButton } from '@/components/settings/projects/feature-grouping-button';
import type { GroupingStatus } from '@/components/settings/projects/feature-grouping-button';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy,
    repositories as assignRepositories,
    store,
    update,
} from '@/routes/projects';

type ProjectRepository = {
    id: string;
    full_name: string;
    tags: RepositoryTag[];
    profile_refreshed_at: string | null;
};

type Project = {
    id: string;
    name: string;
    description: string | null;
    grouping: GroupingStatus;
    repositories: ProjectRepository[];
};

type UnassignedRepository = {
    id: string;
    full_name: string;
    tags: RepositoryTag[];
    profile_refreshed_at: string | null;
    grouping: GroupingStatus;
};

type RepositoryOption = {
    id: string;
    full_name: string;
    project_id: string | null;
};

type Props = {
    projects: Project[];
    repositories: RepositoryOption[];
    unassigned_repositories: UnassignedRepository[];
    tag_kinds: TagKindOption[];
    status?: string | null;
};

/** Case-insensitive substring match used by every search box on the page. */
function matches(value: string | null | undefined, query: string): boolean {
    return (value ?? '').toLowerCase().includes(query);
}

const textareaClass =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none';

function RepositoryTagsDisclosure({
    repository,
    tagKinds,
}: {
    repository: {
        id: string;
        tags: RepositoryTag[];
        profile_refreshed_at: string | null;
    };
    tagKinds: TagKindOption[];
}) {
    return (
        <details className="group w-full text-sm">
            <summary className="flex cursor-pointer list-none items-center gap-1 text-muted-foreground hover:text-foreground">
                <ChevronRight className="size-3.5 transition-transform group-open:rotate-90" />
                Tags ({repository.tags.length})
            </summary>
            <div className="pt-3">
                <RepositoryTagsEditor
                    repositoryId={repository.id}
                    tags={repository.tags}
                    tagKinds={tagKinds}
                    profileRefreshedAt={repository.profile_refreshed_at}
                />
            </div>
        </details>
    );
}

function CreateProjectDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ name: '', description: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    }

    function changeOpen(next: boolean) {
        if (!next) {
            form.reset();
            form.clearErrors();
        }

        onOpenChange(next);
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>New project</DialogTitle>
                        <DialogDescription>
                            Group repositories that belong to one product or
                            client. You can add repositories after creating it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1.5">
                        <Label htmlFor="new-project-name">Name</Label>
                        <Input
                            id="new-project-name"
                            value={form.data.name}
                            maxLength={100}
                            autoFocus
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            required
                        />
                        {form.errors.name && (
                            <p className="text-sm text-destructive">
                                {form.errors.name}
                            </p>
                        )}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="new-project-description">
                            Description
                        </Label>
                        <textarea
                            id="new-project-description"
                            rows={3}
                            maxLength={1000}
                            className={textareaClass}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                        {form.errors.description && (
                            <p className="text-sm text-destructive">
                                {form.errors.description}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => changeOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <Plus className="size-4" />
                            Create project
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ProjectMembersForm({
    project,
    repositories,
    projectNames,
}: {
    project: Project;
    repositories: RepositoryOption[];
    projectNames: Record<string, string>;
}) {
    const members = useForm({
        repository_ids: project.repositories.map((r) => r.id),
    });

    function saveMembers(event: FormEvent) {
        event.preventDefault();
        members.put(assignRepositories(project.id).url, {
            preserveScroll: true,
            onSuccess: () => members.setDefaults(),
        });
    }

    const [filter, setFilter] = useState('');
    const query = filter.trim().toLowerCase();
    const visible = query
        ? repositories.filter((repository) =>
              matches(repository.full_name, query),
          )
        : repositories;

    function toggle(id: string, checked: boolean) {
        members.setData(
            'repository_ids',
            checked
                ? [...members.data.repository_ids, id]
                : members.data.repository_ids.filter((r) => r !== id),
        );
    }

    return (
        <form onSubmit={saveMembers} className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium">Repositories</p>
                <span className="text-xs text-muted-foreground">
                    {members.data.repository_ids.length} selected
                </span>
            </div>
            {repositories.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No repositories are connected yet.
                </p>
            ) : (
                <div className="rounded-md border">
                    <div className="relative border-b">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder="Search repositories"
                            aria-label="Search repositories"
                            className="border-0 pl-9 shadow-none focus-visible:ring-0"
                        />
                    </div>
                    <div className="max-h-64 space-y-1 overflow-y-auto p-2">
                        {visible.length === 0 && (
                            <p className="px-1 py-2 text-sm text-muted-foreground">
                                No repositories match “{filter.trim()}”.
                            </p>
                        )}
                        {visible.map((repository) => {
                            const otherProject =
                                repository.project_id !== null &&
                                repository.project_id !== project.id
                                    ? projectNames[repository.project_id]
                                    : null;

                            return (
                                <div
                                    key={repository.id}
                                    className="flex items-center gap-2 rounded-md px-1 py-1 hover:bg-muted/50"
                                >
                                    <Checkbox
                                        id={`repo-${project.id}-${repository.id}`}
                                        checked={members.data.repository_ids.includes(
                                            repository.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggle(
                                                repository.id,
                                                checked === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor={`repo-${project.id}-${repository.id}`}
                                        className="font-normal"
                                    >
                                        {repository.full_name}
                                    </Label>
                                    {otherProject && (
                                        <span className="text-xs text-muted-foreground">
                                            in {otherProject}
                                        </span>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
            {members.errors.repository_ids && (
                <p className="text-sm text-destructive">
                    {members.errors.repository_ids}
                </p>
            )}
            <Button
                type="submit"
                size="sm"
                disabled={members.processing || !members.isDirty}
            >
                Save repositories
            </Button>
        </form>
    );
}

function ProjectCard({
    project,
    repositories,
    projectNames,
    tagKinds,
    searching,
}: {
    project: Project;
    repositories: RepositoryOption[];
    projectNames: Record<string, string>;
    tagKinds: TagKindOption[];
    searching: boolean;
}) {
    const details = useForm({
        name: project.name,
        description: project.description ?? '',
    });
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [open, setOpen] = useState(false);
    const repositoryCount = project.repositories.length;
    const preview = project.repositories.slice(0, 3);

    // A search that matched this project opens it so the match is visible.
    // Adjusted during render (not in an effect) when the search starts.
    const [wasSearching, setWasSearching] = useState(searching);

    if (searching !== wasSearching) {
        setWasSearching(searching);

        if (searching) {
            setOpen(true);
        }
    }

    function saveDetails(event: FormEvent) {
        event.preventDefault();
        details.put(update(project.id).url, {
            preserveScroll: true,
            onSuccess: () => details.setDefaults(),
        });
    }

    function confirmDelete() {
        setDeleting(true);
        router.delete(destroy(project.id).url, {
            preserveScroll: true,
            onFinish: () => {
                setDeleting(false);
                setDeleteOpen(false);
            },
        });
    }

    return (
        <Card>
            <Collapsible open={open} onOpenChange={setOpen}>
                <CardHeader>
                    <div className="flex items-start justify-between gap-4">
                        <CollapsibleTrigger asChild>
                            <button
                                type="button"
                                className="flex min-w-0 flex-1 items-start gap-3 text-left"
                                aria-expanded={open}
                                aria-label={`${open ? 'Collapse' : 'Expand'} ${project.name}`}
                            >
                                <ChevronRight
                                    className={`mt-1 size-4 shrink-0 text-muted-foreground transition-transform ${open ? 'rotate-90' : ''}`}
                                />
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted">
                                    <FolderKanban className="size-4 text-muted-foreground" />
                                </span>
                                <span className="min-w-0 flex-1 space-y-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <CardTitle className="truncate">
                                            {project.name}
                                        </CardTitle>
                                        <Badge variant="secondary">
                                            {repositoryCount}{' '}
                                            {repositoryCount === 1
                                                ? 'repository'
                                                : 'repositories'}
                                        </Badge>
                                    </span>
                                    {project.description && (
                                        <span className="block truncate text-sm text-muted-foreground">
                                            {project.description}
                                        </span>
                                    )}
                                    {!open && preview.length > 0 && (
                                        <span className="flex flex-wrap gap-1.5 pt-1">
                                            {preview.map((repository) => (
                                                <span
                                                    key={repository.id}
                                                    className="rounded border px-1.5 py-0.5 font-mono text-xs text-muted-foreground"
                                                >
                                                    {repository.full_name}
                                                </span>
                                            ))}
                                            {repositoryCount >
                                                preview.length && (
                                                <span className="px-1 py-0.5 text-xs text-muted-foreground">
                                                    +
                                                    {repositoryCount -
                                                        preview.length}{' '}
                                                    more
                                                </span>
                                            )}
                                        </span>
                                    )}
                                </span>
                            </button>
                        </CollapsibleTrigger>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setDeleteOpen(true)}
                        >
                            <Trash2 className="size-4" />
                            Delete
                        </Button>
                    </div>
                </CardHeader>
                <CollapsibleContent>
                    <CardContent className="space-y-6">
                        <form onSubmit={saveDetails} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor={`name-${project.id}`}>
                                    Name
                                </Label>
                                <Input
                                    id={`name-${project.id}`}
                                    value={details.data.name}
                                    maxLength={100}
                                    onChange={(e) =>
                                        details.setData('name', e.target.value)
                                    }
                                    required
                                />
                                {details.errors.name && (
                                    <p className="text-sm text-destructive">
                                        {details.errors.name}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor={`description-${project.id}`}>
                                    Description
                                </Label>
                                <textarea
                                    id={`description-${project.id}`}
                                    rows={2}
                                    maxLength={1000}
                                    className={textareaClass}
                                    value={details.data.description}
                                    onChange={(e) =>
                                        details.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={
                                    details.processing || !details.isDirty
                                }
                            >
                                Save details
                            </Button>
                        </form>

                        <ProjectMembersForm
                            // Remount only when membership changes server-side (here or
                            // in another project), so unrelated saves keep unsaved edits.
                            key={project.repositories
                                .map((r) => r.id)
                                .join(',')}
                            project={project}
                            repositories={repositories}
                            projectNames={projectNames}
                        />

                        {project.repositories.length > 0 && (
                            <div className="space-y-3 border-t pt-4">
                                <p className="text-sm font-medium">
                                    Profile tags
                                </p>
                                {project.repositories.map((repository) => (
                                    <div
                                        key={repository.id}
                                        className="space-y-1"
                                    >
                                        <p className="text-sm">
                                            {repository.full_name}
                                        </p>
                                        <RepositoryTagsDisclosure
                                            repository={repository}
                                            tagKinds={tagKinds}
                                        />
                                    </div>
                                ))}
                            </div>
                        )}

                        <div className="space-y-2 border-t pt-4">
                            <p className="text-sm font-medium">Features</p>
                            <p className="text-sm text-muted-foreground">
                                Group tasks recorded before features existed.
                            </p>
                            <FeatureGroupingButton
                                scope={{ project_id: project.id }}
                                initial={project.grouping}
                            />
                        </div>
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete {project.name}?</DialogTitle>
                        <DialogDescription>
                            Its repositories are kept and become unassigned.
                            This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeleteOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={deleting}
                            onClick={confirmDelete}
                        >
                            Delete project
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

function UnassignedRepositoriesCard({
    repositories,
    tagKinds,
}: {
    repositories: UnassignedRepository[];
    tagKinds: TagKindOption[];
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex items-center gap-2">
                    <CardTitle>Repositories without a project</CardTitle>
                    <Badge variant="secondary">{repositories.length}</Badge>
                </div>
                <CardDescription>
                    Their features are kept per repository. Group tasks recorded
                    before features existed.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {repositories.map((repository) => (
                    <div
                        key={repository.id}
                        className="flex flex-wrap items-start justify-between gap-3 border-b pb-4 last:border-b-0 last:pb-0"
                    >
                        <p className="pt-1.5 text-sm font-medium">
                            {repository.full_name}
                        </p>
                        <FeatureGroupingButton
                            scope={{ repository_id: repository.id }}
                            initial={repository.grouping}
                        />
                        <RepositoryTagsDisclosure
                            repository={repository}
                            tagKinds={tagKinds}
                        />
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

export default function Projects({
    projects,
    repositories,
    unassigned_repositories,
    tag_kinds,
    status,
}: Props) {
    const projectNames = Object.fromEntries(
        projects.map((project) => [project.id, project.name]),
    );
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState('');
    const query = search.trim().toLowerCase();

    const visibleProjects = useMemo(
        () =>
            query
                ? projects.filter(
                      (project) =>
                          matches(project.name, query) ||
                          matches(project.description, query) ||
                          project.repositories.some((repository) =>
                              matches(repository.full_name, query),
                          ),
                  )
                : projects,
        [projects, query],
    );
    const visibleUnassigned = useMemo(
        () =>
            query
                ? unassigned_repositories.filter((repository) =>
                      matches(repository.full_name, query),
                  )
                : unassigned_repositories,
        [unassigned_repositories, query],
    );
    const nothingMatches =
        query !== '' &&
        visibleProjects.length === 0 &&
        visibleUnassigned.length === 0;

    return (
        <>
            <Head title="Projects" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Projects
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Group repositories into projects so features can
                            span them. A repository belongs to at most one
                            project; assigning it here moves it.
                        </p>
                    </div>
                    <Button type="button" onClick={() => setCreating(true)}>
                        <Plus className="size-4" />
                        New project
                    </Button>
                </div>

                {status && (
                    <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                        {status}
                    </p>
                )}

                {(projects.length > 0 ||
                    unassigned_repositories.length > 0) && (
                    <div className="relative">
                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search projects and repositories"
                            aria-label="Search projects and repositories"
                            className="pl-9"
                        />
                    </div>
                )}

                {projects.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed px-6 py-12 text-center">
                        <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                            <FolderPlus className="size-5 text-muted-foreground" />
                        </span>
                        <div className="space-y-1">
                            <p className="font-medium">No projects yet</p>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                Create a project to group repositories that
                                belong to one product or client.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setCreating(true)}
                        >
                            <Plus className="size-4" />
                            New project
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {visibleProjects.map((project) => (
                            <ProjectCard
                                key={project.id}
                                project={project}
                                repositories={repositories}
                                projectNames={projectNames}
                                tagKinds={tag_kinds}
                                searching={query !== ''}
                            />
                        ))}
                    </div>
                )}

                {visibleUnassigned.length > 0 && (
                    <UnassignedRepositoriesCard
                        repositories={visibleUnassigned}
                        tagKinds={tag_kinds}
                    />
                )}

                {nothingMatches && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        Nothing matches “{search.trim()}”.
                    </p>
                )}
            </div>

            <CreateProjectDialog open={creating} onOpenChange={setCreating} />
        </>
    );
}
