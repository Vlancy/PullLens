import { Head, useForm } from '@inertiajs/react';
import { Lock, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// ─── Types ───────────────────────────────────────────────────────────────────

type PermissionOption = {
    value: string;
    label: string;
};

type RoleRow = {
    value: string;
    label: string;
    /** Permission names currently granted to this role. */
    permissions: string[];
    /** Permissions this role may never give up; rendered as fixed, ticked boxes. */
    locked_permissions: string[];
    users_count: number;
    is_admin: boolean;
    /** Created by an administrator: can be renamed and deleted. */
    is_custom: boolean;
};

type Props = {
    roles: RoleRow[];
    permissions: PermissionOption[];
};

// ─── Permission checkboxes ────────────────────────────────────────────────────

function PermissionGrid({
    idPrefix,
    permissions,
    selected,
    locked,
    onToggle,
}: {
    idPrefix: string;
    permissions: PermissionOption[];
    selected: string[];
    locked: string[];
    onToggle: (permission: string, granted: boolean) => void;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {permissions.map((permission) => {
                const isLocked = locked.includes(permission.value);
                const checked = isLocked || selected.includes(permission.value);
                const id = `${idPrefix}-${permission.value}`;

                return (
                    <div
                        key={permission.value}
                        className="flex items-start gap-2.5 rounded-md border p-3"
                    >
                        <Checkbox
                            id={id}
                            checked={checked}
                            disabled={isLocked}
                            onCheckedChange={(value) =>
                                onToggle(permission.value, value === true)
                            }
                        />
                        <div className="min-w-0 space-y-0.5">
                            <Label
                                htmlFor={id}
                                className="flex items-center gap-1.5 leading-tight"
                            >
                                {permission.label}
                                {isLocked && (
                                    <Lock className="h-3 w-3 shrink-0 text-muted-foreground" />
                                )}
                            </Label>
                            <p className="truncate font-mono text-xs text-muted-foreground">
                                {permission.value}
                            </p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function togglePermission(
    current: string[],
    permission: string,
    granted: boolean,
): string[] {
    return granted
        ? [...current, permission]
        : current.filter((value) => value !== permission);
}

// ─── Single role editor ───────────────────────────────────────────────────────

function RoleCard({
    role,
    permissions,
}: {
    role: RoleRow;
    permissions: PermissionOption[];
}) {
    const { data, setData, put, processing, errors, isDirty } = useForm<{
        label: string;
        permissions: string[];
    }>({
        label: role.label,
        permissions: role.permissions,
    });
    const destroyForm = useForm({});
    const [deleteOpen, setDeleteOpen] = useState(false);
    const destroyErrors = destroyForm.errors as Record<string, string>;

    function toggle(permission: string, granted: boolean) {
        setData(
            'permissions',
            togglePermission(data.permissions, permission, granted),
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        put(`/admin/roles/${role.value}`, { preserveScroll: true });
    }

    function handleDelete() {
        destroyForm.delete(`/admin/roles/${role.value}`, {
            preserveScroll: true,
            onFinish: () => setDeleteOpen(false),
        });
    }

    return (
        <Card>
            <CardHeader className="pb-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="space-y-1">
                        <CardTitle className="flex items-center gap-2 text-base">
                            {role.is_custom ? (
                                <Input
                                    aria-label="Role name"
                                    form={`role-${role.value}`}
                                    value={data.label}
                                    maxLength={50}
                                    onChange={(e) =>
                                        setData('label', e.target.value)
                                    }
                                    className="h-8 w-56"
                                />
                            ) : (
                                role.label
                            )}
                            {role.is_custom && (
                                <Badge variant="outline" className="text-xs">
                                    Custom
                                </Badge>
                            )}
                            {role.is_admin && (
                                <Badge
                                    variant="secondary"
                                    className="gap-1 text-xs"
                                >
                                    <ShieldCheck className="h-3 w-3" />
                                    Full control
                                </Badge>
                            )}
                        </CardTitle>
                        <CardDescription>
                            {role.users_count === 1
                                ? '1 user has this role'
                                : `${role.users_count} users have this role`}
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        {role.is_custom && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setDeleteOpen(true)}
                                disabled={role.users_count > 0}
                                title={
                                    role.users_count > 0
                                        ? 'Reassign its users before deleting this role'
                                        : 'Delete role'
                                }
                            >
                                <Trash2 className="h-4 w-4" />
                                <span className="sr-only">Delete role</span>
                            </Button>
                        )}
                        <Button
                            type="submit"
                            form={`role-${role.value}`}
                            size="sm"
                            disabled={processing || !isDirty}
                        >
                            Save changes
                        </Button>
                    </div>
                </div>
            </CardHeader>

            <CardContent>
                <form id={`role-${role.value}`} onSubmit={submit}>
                    <PermissionGrid
                        idPrefix={role.value}
                        permissions={permissions}
                        selected={data.permissions}
                        locked={role.locked_permissions}
                        onToggle={toggle}
                    />

                    {errors.label && (
                        <p className="mt-3 text-sm text-destructive">
                            {errors.label}
                        </p>
                    )}
                    {errors.permissions && (
                        <p className="mt-3 text-sm text-destructive">
                            {errors.permissions}
                        </p>
                    )}
                    {destroyErrors.role && (
                        <p className="mt-3 text-sm text-destructive">
                            {destroyErrors.role}
                        </p>
                    )}
                </form>
            </CardContent>

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete role?</DialogTitle>
                        <DialogDescription>
                            This will permanently delete the{' '}
                            <strong>{role.label}</strong> role. This action
                            cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setDeleteOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleDelete}
                            disabled={destroyForm.processing}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

// ─── New role form ────────────────────────────────────────────────────────────

function NewRoleCard({
    permissions,
    onDone,
}: {
    permissions: PermissionOption[];
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        label: string;
        permissions: string[];
    }>({
        label: '',
        permissions: [],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/admin/roles', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    }

    return (
        <Card className="border-dashed">
            <CardHeader className="pb-4">
                <CardTitle className="text-base">New custom role</CardTitle>
                <CardDescription>
                    Name the role and pick what it may do. You can assign it to
                    users from the Users page.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="max-w-sm space-y-1.5">
                        <Label htmlFor="new-role-label">Role name</Label>
                        <Input
                            id="new-role-label"
                            value={data.label}
                            maxLength={50}
                            autoFocus
                            placeholder="e.g. Security reviewer"
                            onChange={(e) => setData('label', e.target.value)}
                        />
                        {errors.label && (
                            <p className="text-sm text-destructive">
                                {errors.label}
                            </p>
                        )}
                    </div>

                    <PermissionGrid
                        idPrefix="new-role"
                        permissions={permissions}
                        selected={data.permissions}
                        locked={[]}
                        onToggle={(permission, granted) =>
                            setData(
                                'permissions',
                                togglePermission(
                                    data.permissions,
                                    permission,
                                    granted,
                                ),
                            )
                        }
                    />
                    {errors.permissions && (
                        <p className="text-sm text-destructive">
                            {errors.permissions}
                        </p>
                    )}

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || data.label.trim() === ''}
                        >
                            Create role
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Roles({ roles, permissions }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Roles & permissions" />

            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">
                            Roles &amp; permissions
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Choose what each role is allowed to do. Changes
                            apply immediately to everyone holding that role.
                            Locked permissions cannot be removed - they are what
                            keeps the admin area reachable.
                        </p>
                    </div>
                    {!creating && (
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="h-4 w-4" />
                            New role
                        </Button>
                    )}
                </div>

                {creating && (
                    <NewRoleCard
                        permissions={permissions}
                        onDone={() => setCreating(false)}
                    />
                )}

                <div className="space-y-4">
                    {roles.map((role) => (
                        <RoleCard
                            key={role.value}
                            role={role}
                            permissions={permissions}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}
