<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Services\Users\RolePermissionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Lets an administrator decide what each role is allowed to do, and add or remove
 * custom roles alongside the built-in ones.
 *
 * Changes take effect immediately for every user holding the role - the permission
 * cache is cleared by RolePermissionManager as part of the write.
 */
class RoleController extends Controller
{
    /**
     * Inject the role permission manager this class delegates to.
     */
    public function __construct(private readonly RolePermissionManager $roles) {}

    /**
     * Show the role/permission matrix.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);

        return Inertia::render('admin/roles', [
            'roles' => $this->roles->matrix(),
            'permissions' => $this->roles->availablePermissions(),
        ]);
    }

    /**
     * Create a custom role.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create($request->label(), $request->permissions());

        return to_route('admin.roles.index')
            ->with('status', "Role {$this->roles->labelFor($role)} created.");
    }

    /**
     * Replace one role's permissions (and rename it, when custom).
     */
    public function update(UpdateRolePermissionsRequest $request): RedirectResponse
    {
        $role = $request->role();

        $this->roles->sync($role, $request->permissions(), $request->label());

        $label = $this->roles->labelFor(Role::findByName($role, config('auth.defaults.guard', 'web')));

        return to_route('admin.roles.index')
            ->with('status', "Permissions updated for {$label}.");
    }

    /**
     * Delete a custom role that nobody holds.
     */
    public function destroy(Request $request, string $role): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);
        abort_unless(Role::query()->where('name', $role)->exists(), 404);

        $this->roles->delete($role);

        return to_route('admin.roles.index')->with('status', 'Role deleted.');
    }
}
