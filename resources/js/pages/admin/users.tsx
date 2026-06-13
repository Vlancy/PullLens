import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, Pencil, Plus, Search, Trash2, UserRound, X } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { cn } from '@/lib/utils';
import { index as usersIndex } from '@/routes/admin/users';
import type { Auth } from '@/types';

// ─── Types ───────────────────────────────────────────────────────────────────

type UserItem = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    created_at: string;
    update_url: string;
    destroy_url: string;
};

type Pagination = {
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    users: UserItem[];
    store_url: string;
    filters: { search: string };
    pagination: Pagination;
};

// ─── Add User Form ────────────────────────────────────────────────────────────

function AddUserForm({ storeUrl, onClose }: { storeUrl: string; onClose: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post(storeUrl, {
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    }

    return (
        <Card className="border-primary/30 bg-primary/5">
            <CardHeader className="pb-4">
                <div className="flex items-center justify-between">
                    <CardTitle className="text-base">New User</CardTitle>
                    <Button variant="ghost" size="icon" className="h-7 w-7" onClick={onClose}>
                        <X className="h-4 w-4" />
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="new-name">Name</Label>
                            <Input
                                id="new-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Full name"
                                autoFocus
                            />
                            {errors.name && (
                                <p className="text-sm text-destructive">{errors.name}</p>
                            )}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="new-email">Email</Label>
                            <Input
                                id="new-email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="user@example.com"
                            />
                            {errors.email && (
                                <p className="text-sm text-destructive">{errors.email}</p>
                            )}
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="new-password">Password</Label>
                            <Input
                                id="new-password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Secure password"
                            />
                            {errors.password && (
                                <p className="text-sm text-destructive">{errors.password}</p>
                            )}
                        </div>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create User
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

// ─── Edit User Form ───────────────────────────────────────────────────────────

function EditUserForm({ user, onClose }: { user: UserItem; onClose: () => void }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name,
        email: user.email,
        password: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        put(user.update_url, { onSuccess: onClose });
    }

    return (
        <form onSubmit={submit} className="space-y-4 pt-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor={`name-${user.id}`}>Name</Label>
                    <Input
                        id={`name-${user.id}`}
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        autoFocus
                    />
                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={`email-${user.id}`}>Email</Label>
                    <Input
                        id={`email-${user.id}`}
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                </div>
                <div className="space-y-1.5 sm:col-span-2">
                    <Label htmlFor={`password-${user.id}`}>
                        New Password{' '}
                        <span className="text-xs text-muted-foreground">(leave blank to keep current)</span>
                    </Label>
                    <Input
                        id={`password-${user.id}`}
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder="New password"
                    />
                    {errors.password && (
                        <p className="text-sm text-destructive">{errors.password}</p>
                    )}
                </div>
            </div>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    Save Changes
                </Button>
            </div>
        </form>
    );
}

// ─── User Card ────────────────────────────────────────────────────────────────

function UserCard({ user, isSelf }: { user: UserItem; isSelf: boolean }) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const { delete: deleteUser, processing } = useForm({});

    function handleDelete() {
        deleteUser(user.destroy_url, { onSuccess: () => setDeleteOpen(false) });
    }

    const joinedAt = new Date(user.created_at).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });

    return (
        <>
            <Card className={cn('transition-colors', isSelf && 'border-primary/30')}>
                <CardHeader className="pb-3">
                    <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                            <UserRound className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <CardTitle className="text-base">{user.name}</CardTitle>
                                {isSelf && (
                                    <Badge variant="outline" className="text-xs">
                                        You
                                    </Badge>
                                )}
                                {user.email_verified_at ? (
                                    <Badge variant="secondary" className="text-xs">
                                        Verified
                                    </Badge>
                                ) : (
                                    <Badge variant="destructive" className="text-xs">
                                        Unverified
                                    </Badge>
                                )}
                            </div>
                            <CardDescription className="mt-0.5 truncate">{user.email}</CardDescription>
                        </div>
                        <div className="flex shrink-0 items-center gap-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8"
                                onClick={() => setEditOpen((o) => !o)}
                                disabled={isSelf}
                                title={isSelf ? 'Use profile settings to edit your own account' : 'Edit user'}
                            >
                                {editOpen ? (
                                    <ChevronDown className="h-4 w-4" />
                                ) : (
                                    <Pencil className="h-4 w-4" />
                                )}
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-destructive hover:text-destructive"
                                onClick={() => setDeleteOpen(true)}
                                disabled={isSelf}
                                title={isSelf ? 'You cannot delete your own account' : 'Delete user'}
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    {isSelf && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Edit your own account via{' '}
                            <a href="/user/settings/profile" className="underline underline-offset-2">
                                Profile Settings
                            </a>
                            .
                        </p>
                    )}
                </CardHeader>

                {editOpen && !isSelf && (
                    <CardContent className="border-t pt-0">
                        <EditUserForm user={user} onClose={() => setEditOpen(false)} />
                    </CardContent>
                )}

                <CardContent className={cn('border-t pt-3 pb-3', editOpen && !isSelf && 'hidden')}>
                    <p className="text-xs text-muted-foreground">Joined {joinedAt}</p>
                </CardContent>
            </Card>

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete user?</DialogTitle>
                        <DialogDescription>
                            This will permanently delete <strong>{user.name}</strong> ({user.email}).
                            This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDeleteOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={handleDelete} disabled={processing}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Users({ users, store_url, filters, pagination }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const currentUserId = auth.user.id;
    const [addOpen, setAddOpen] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        const timer = setTimeout(() => {
            router.get(
                usersIndex().url,
                { search: search || undefined },
                { preserveState: true, replace: true },
            );
        }, 350);
        return () => clearTimeout(timer);
    }, [search]);

    return (
        <>
            <Head title="Users" />

            <div className="space-y-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">Users</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage all user accounts. You cannot edit or delete your own account
                            from here — use Profile Settings instead.
                        </p>
                    </div>
                    <Button onClick={() => setAddOpen((o) => !o)} size="sm">
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add User
                    </Button>
                </div>

                {/* Add form */}
                {addOpen && (
                    <AddUserForm storeUrl={store_url} onClose={() => setAddOpen(false)} />
                )}

                {/* Search */}
                <div className="relative">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        className="pl-9"
                        placeholder="Search by name or email…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    {search && (
                        <button
                            className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            onClick={() => setSearch('')}
                            aria-label="Clear search"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    )}
                </div>

                {/* List */}
                {users.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center">
                        <UserRound className="mb-3 h-10 w-10 text-muted-foreground/40" />
                        <p className="text-sm font-medium">
                            {search ? 'No users match your search' : 'No users yet'}
                        </p>
                        {!search && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Add the first user with the button above.
                            </p>
                        )}
                    </div>
                ) : (
                    <div className="space-y-3">
                        {users.map((user) => (
                            <UserCard
                                key={user.id}
                                user={user}
                                isSelf={user.id === currentUserId}
                            />
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {pagination.last_page > 1 && (
                    <div className="flex items-center justify-between border-t pt-4">
                        <p className="text-sm text-muted-foreground">
                            Page {pagination.current_page} of {pagination.last_page}
                            <span className="ml-2 text-muted-foreground/60">
                                ({pagination.total} total)
                            </span>
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!pagination.prev_page_url}
                                onClick={() =>
                                    pagination.prev_page_url &&
                                    router.visit(pagination.prev_page_url, { preserveState: true })
                                }
                            >
                                <ChevronLeft className="mr-1 h-4 w-4" />
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!pagination.next_page_url}
                                onClick={() =>
                                    pagination.next_page_url &&
                                    router.visit(pagination.next_page_url, { preserveState: true })
                                }
                            >
                                Next
                                <ChevronRight className="ml-1 h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
