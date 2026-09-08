import { Head, useForm } from '@inertiajs/react';
import { Lock, ShieldCheck } from 'lucide-react';
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
};

type Props = {
    roles: RoleRow[];
    permissions: PermissionOption[];
};

// ─── Single role editor ───────────────────────────────────────────────────────

function RoleCard({
    role,
    permissions,
}: {
    role: RoleRow;
    permissions: PermissionOption[];
}) {
    const { data, setData, put, processing, errors, isDirty } = useForm<{
        permissions: string[];
    }>({
        permissions: role.permissions,
    });

    function toggle(permission: string, granted: boolean) {
        setData(
            'permissions',
            granted
                ? [...data.permissions, permission]
                : data.permissions.filter((value) => value !== permission),
        );
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        put(`/admin/roles/${role.value}`, { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader className="pb-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="space-y-1">
                        <CardTitle className="flex items-center gap-2 text-base">
                            {role.label}
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
                    <Button
                        type="submit"
                        form={`role-${role.value}`}
                        size="sm"
                        disabled={processing || !isDirty}
                    >
                        Save changes
                    </Button>
                </div>
            </CardHeader>

            <CardContent>
                <form id={`role-${role.value}`} onSubmit={submit}>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {permissions.map((permission) => {
                            const locked = role.locked_permissions.includes(
                                permission.value,
                            );
                            const checked =
                                locked ||
                                data.permissions.includes(permission.value);

                            return (
                                <div
                                    key={permission.value}
                                    className="flex items-start gap-2.5 rounded-md border p-3"
                                >
                                    <Checkbox
                                        id={`${role.value}-${permission.value}`}
                                        checked={checked}
                                        disabled={locked}
                                        onCheckedChange={(value) =>
                                            toggle(
                                                permission.value,
                                                value === true,
                                            )
                                        }
                                    />
                                    <div className="min-w-0 space-y-0.5">
                                        <Label
                                            htmlFor={`${role.value}-${permission.value}`}
                                            className="flex items-center gap-1.5 leading-tight"
                                        >
                                            {permission.label}
                                            {locked && (
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

                    {errors.permissions && (
                        <p className="mt-3 text-sm text-destructive">
                            {errors.permissions}
                        </p>
                    )}
                </form>
            </CardContent>
        </Card>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Roles({ roles, permissions }: Props) {
    return (
        <>
            <Head title="Roles & permissions" />

            <div className="space-y-6 p-4 md:p-6">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">
                        Roles &amp; permissions
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Choose what each role is allowed to do. Changes apply
                        immediately to everyone holding that role. Locked
                        permissions cannot be removed - they are what keeps the
                        admin area reachable.
                    </p>
                </div>

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
