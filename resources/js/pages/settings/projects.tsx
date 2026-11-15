import { Head, router, useForm } from '@inertiajs/react';
import { FolderKanban, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FeatureGroupingButton } from '@/components/settings/projects/feature-grouping-button';
import type { GroupingStatus } from '@/components/settings/projects/feature-grouping-button';
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

type ProjectRepository = { id: string; full_name: string };

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
    status?: string | null;
};

const textareaClass =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none';

function CreateProjectForm() {
    const form = useForm({ name: '', description: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>New project</CardTitle>
                <CardDescription>
                    Group repositories that belong to one product or client.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="new-project-name">Name</Label>
                        <Input
                            id="new-project-name"
                            value={form.data.name}
                            maxLength={100}
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
                            rows={2}
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
                    <Button type="submit" disabled={form.processing}>
                        <Plus className="size-4" />
                        Create project
                    </Button>
                </form>
            </CardContent>
        </Card>
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
            <p className="text-sm font-medium">Repositories</p>
            {repositories.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No repositories are connected yet.
                </p>
            ) : (
                <div className="max-h-64 space-y-2 overflow-y-auto rounded-md border p-3">
                    {repositories.map((repository) => {
                        const otherProject =
                            repository.project_id !== null &&
                            repository.project_id !== project.id
                                ? projectNames[repository.project_id]
                                : null;

                        return (
                            <div
                                key={repository.id}
                                className="flex items-center gap-2"
                            >
                                <Checkbox
                                    id={`repo-${project.id}-${repository.id}`}
                                    checked={members.data.repository_ids.includes(
                                        repository.id,
                                    )}
                                    onCheckedChange={(checked) =>
                                        toggle(repository.id, checked === true)
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
}: {
    project: Project;
    repositories: RepositoryOption[];
    projectNames: Record<string, string>;
}) {
    const details = useForm({
        name: project.name,
        description: project.description ?? '',
    });
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

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
            <CardHeader>
                <div className="flex items-start justify-between gap-4">
                    <div className="flex items-center gap-2">
                        <FolderKanban className="size-4 text-muted-foreground" />
                        <CardTitle>{project.name}</CardTitle>
                    </div>
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
            <CardContent className="space-y-6">
                <form onSubmit={saveDetails} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor={`name-${project.id}`}>Name</Label>
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
                                details.setData('description', e.target.value)
                            }
                        />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        disabled={details.processing || !details.isDirty}
                    >
                        Save details
                    </Button>
                </form>

                <ProjectMembersForm
                    // Remount only when membership changes server-side (here or
                    // in another project), so unrelated saves keep unsaved edits.
                    key={project.repositories.map((r) => r.id).join(',')}
                    project={project}
                    repositories={repositories}
                    projectNames={projectNames}
                />

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
}: {
    repositories: UnassignedRepository[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Repositories without a project</CardTitle>
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
    status,
}: Props) {
    const projectNames = Object.fromEntries(
        projects.map((project) => [project.id, project.name]),
    );

    return (
        <>
            <Head title="Projects" />

            <div className="space-y-6 p-4 md:p-6">
                <div>
                    <p className="text-lg font-semibold">Projects</p>
                    <p className="text-sm text-muted-foreground">
                        Group repositories into projects. A repository belongs
                        to at most one project; assigning it here moves it.
                    </p>
                </div>

                {status && (
                    <p className="text-sm text-muted-foreground">{status}</p>
                )}

                <CreateProjectForm />

                {projects.map((project) => (
                    <ProjectCard
                        key={project.id}
                        project={project}
                        repositories={repositories}
                        projectNames={projectNames}
                    />
                ))}

                {unassigned_repositories.length > 0 && (
                    <UnassignedRepositoriesCard
                        repositories={unassigned_repositories}
                    />
                )}
            </div>
        </>
    );
}
