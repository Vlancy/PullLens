import { router } from '@inertiajs/react';
import { FolderInput, GitMerge, MoreHorizontal, Pencil } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { update as assignFeature } from '@/routes/tasks/feature';
import { merge, update as renameFeature } from '@/routes/tasks/features';

// ─── Types ────────────────────────────────────────────────────────────────────

/** A feature a manager can merge into or move a task into. */
export type FeatureOption = {
    id: string;
    name: string;
    scope_key: string;
    scope_label: string | null;
};

/** The parts of a feature row the actions need. */
export type ManagedFeature = {
    id: string;
    name: string;
    description: string | null;
    scope_key: string | null;
};

/** The parts of a task the move dialog needs. */
export type MovableTask = {
    id: string;
    title: string;
    scope_key: string | null;
    feature: { id: string; name: string } | null;
};

type Errors = Record<string, string>;

/** Radix Select cannot hold an empty value, so the special choices need sentinels. */
const NEW_FEATURE = '__new';
const NO_FEATURE = '__none';

const textareaClass =
    'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none';

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

/** Features a task or feature in `scopeKey` may join: same scope, never itself. */
function sameScope(
    options: FeatureOption[],
    scopeKey: string | null,
    excludeId?: string,
): FeatureOption[] {
    return options.filter(
        (option) => option.scope_key === scopeKey && option.id !== excludeId,
    );
}

// ─── Feature menu: rename and merge ───────────────────────────────────────────

export function FeatureMenu({
    feature,
    options,
}: {
    feature: ManagedFeature;
    options: FeatureOption[];
}) {
    const [dialog, setDialog] = useState<'rename' | 'merge' | null>(null);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="size-8 p-0"
                        aria-label={`Manage ${feature.name}`}
                    >
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setDialog('rename')}>
                        <Pencil className="size-4" />
                        Rename
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => setDialog('merge')}>
                        <GitMerge className="size-4" />
                        Merge into…
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Mounted only while open, so each opening starts from the current values. */}
            {dialog === 'rename' && (
                <RenameDialog
                    feature={feature}
                    open
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'merge' && (
                <MergeDialog
                    feature={feature}
                    targets={sameScope(options, feature.scope_key, feature.id)}
                    open
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

function RenameDialog({
    feature,
    open,
    onClose,
}: {
    feature: ManagedFeature;
    open: boolean;
    onClose: () => void;
}) {
    const [name, setName] = useState(feature.name);
    // The endpoint clears an omitted description, so the current one is always sent.
    const [description, setDescription] = useState(feature.description ?? '');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();
        router.put(
            renameFeature({ taskFeature: feature.id }).url,
            { name, description },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: () => {
                    setErrors({});
                    onClose();
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Rename feature</DialogTitle>
                        <DialogDescription>
                            A manual name is kept - later reviews will not
                            rename it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1.5">
                        <Label htmlFor={`feature-name-${feature.id}`}>
                            Name
                        </Label>
                        <Input
                            id={`feature-name-${feature.id}`}
                            value={name}
                            maxLength={120}
                            required
                            onChange={(e) => setName(e.target.value)}
                        />
                        <FieldError message={errors.name} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={`feature-description-${feature.id}`}>
                            Description
                        </Label>
                        <textarea
                            id={`feature-description-${feature.id}`}
                            rows={3}
                            maxLength={2000}
                            className={textareaClass}
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                        />
                        <FieldError message={errors.description} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function MergeDialog({
    feature,
    targets,
    open,
    onClose,
}: {
    feature: ManagedFeature;
    targets: FeatureOption[];
    open: boolean;
    onClose: () => void;
}) {
    const [target, setTarget] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();
        router.post(
            merge({ taskFeature: feature.id }).url,
            { into_id: target },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: setErrors,
                onSuccess: () => {
                    setErrors({});
                    setTarget('');
                    onClose();
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Merge {feature.name}</DialogTitle>
                        <DialogDescription>
                            Its tasks move into the feature you pick, and{' '}
                            {feature.name} stops appearing on the board.
                        </DialogDescription>
                    </DialogHeader>
                    {targets.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            There is no other feature in the same project or
                            repository to merge into.
                        </p>
                    ) : (
                        <div className="space-y-1.5">
                            <Label>Merge into</Label>
                            <Select value={target} onValueChange={setTarget}>
                                <SelectTrigger aria-label="Merge into">
                                    <SelectValue placeholder="Choose a feature" />
                                </SelectTrigger>
                                <SelectContent>
                                    {targets.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <FieldError message={errors.into_id} />
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || target === ''}
                        >
                            Merge
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ─── Move a task to another feature ───────────────────────────────────────────

export function MoveTaskButton({
    task,
    options,
}: {
    task: MovableTask;
    options: FeatureOption[];
}) {
    const [open, setOpen] = useState(false);
    const [choice, setChoice] = useState('');
    const [newName, setNewName] = useState('');
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);

    const targets = sameScope(options, task.scope_key, task.feature?.id);

    function payload(): Record<string, string | null> {
        if (choice === NEW_FEATURE) {
            return { new_name: newName };
        }

        return { feature_id: choice === NO_FEATURE ? null : choice };
    }

    function close() {
        setOpen(false);
        setChoice('');
        setNewName('');
        setErrors({});
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        router.put(assignFeature({ pullRequestTask: task.id }).url, payload(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: setErrors,
            onSuccess: close,
        });
    }

    const ready =
        choice !== '' && (choice !== NEW_FEATURE || newName.trim() !== '');

    return (
        <>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs"
                onClick={() => setOpen(true)}
            >
                <FolderInput className="size-3.5" />
                Move
            </Button>

            <Dialog open={open} onOpenChange={(next) => !next && close()}>
                <DialogContent className="max-w-md">
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Move task</DialogTitle>
                            <DialogDescription>
                                {task.title} - a manual move is kept by later
                                reviews.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-1.5">
                            <Label>Feature</Label>
                            <Select value={choice} onValueChange={setChoice}>
                                <SelectTrigger aria-label="Feature">
                                    <SelectValue placeholder="Choose a feature" />
                                </SelectTrigger>
                                <SelectContent>
                                    {targets.map((option) => (
                                        <SelectItem
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </SelectItem>
                                    ))}
                                    <SelectItem value={NEW_FEATURE}>
                                        New feature…
                                    </SelectItem>
                                    {task.feature && (
                                        <SelectItem value={NO_FEATURE}>
                                            No feature (ungrouped)
                                        </SelectItem>
                                    )}
                                </SelectContent>
                            </Select>
                            <FieldError message={errors.feature_id} />
                        </div>
                        {choice === NEW_FEATURE && (
                            <div className="space-y-1.5">
                                <Label htmlFor={`new-feature-${task.id}`}>
                                    New feature name
                                </Label>
                                <Input
                                    id={`new-feature-${task.id}`}
                                    value={newName}
                                    maxLength={120}
                                    required
                                    onChange={(e) => setNewName(e.target.value)}
                                />
                                <FieldError message={errors.new_name} />
                            </div>
                        )}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={close}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={processing || !ready}
                            >
                                Move
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
