<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use App\Services\Users\RolePermissionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets an administrator decide what each role is allowed to do.
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
     * Replace one role's permissions.
     */
    public function update(UpdateRolePermissionsRequest $request): RedirectResponse
    {
        $role = $request->role();

        $this->roles->sync($role, $request->permissions());

        return to_route('admin.roles.index')
            ->with('status', "Permissions updated for {$role->label()}.");
    }
}
