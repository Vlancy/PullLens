<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Users\RepositoryAccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use App\Repositories\Contracts\Users\UserRepositoryInterface;
use App\Services\Users\RolePermissionManager;
use App\Services\Users\UserAccountManager;
use App\Support\Presenters\Users\UserPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Administrator-only management of user accounts.
 *
 * Registration is disabled application-wide, so this controller is the sole path
 * by which accounts come into existence. It stays transport-only: authorization is
 * in the form requests and UserPolicy, persistence in UserRepository, and the
 * create/update/delete invariants in UserAccountManager.
 */
class UserController extends Controller
{
    /**
     * Inject the user repository, account manager and role manager this class delegates to.
     */
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserAccountManager $accounts,
        private readonly RolePermissionManager $roles,
    ) {}

    /**
     * List accounts with keyword search and pagination.
     */
    public function index(IndexUserRequest $request): Response
    {
        $paginator = $this->users->search($request->keyword(), $request->perPage());

        return Inertia::render('admin/users', [
            'users' => UserPresenter::collection($paginator->items()),
            'roles' => $this->roles->options(),
            'repositories' => $this->repositoryOptions(),
            'access_levels' => RepositoryAccessLevel::options(),
            'manage_roles_url' => route('admin.roles.index'),
            'store_url' => route('admin.users.store'),
            'filters' => ['search' => $request->keyword()],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Provision a new account and grant it a role.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->accounts->create(
            $request->userAttributes(),
            $request->role(),
            $request->repositoryGrants(),
        );

        return to_route('admin.users.index')->with('status', 'User created.');
    }

    /**
     * Update an existing account. Self-editing is rejected by UserPolicy::update().
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->accounts->update(
            $user,
            $request->userAttributes(),
            $request->role(),
            $request->repositoryGrants(),
        );

        return to_route('admin.users.index')->with('status', 'User updated.');
    }

    /**
     * Delete an account. Self-deletion is rejected by UserPolicy::delete().
     */
    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $this->accounts->delete($user);

        return to_route('admin.users.index')->with('status', 'User deleted.');
    }

    /**
     * Repositories that can be granted to a scoped user.
     *
     * @return array<int, array<string, mixed>>
     */
    private function repositoryOptions(): array
    {
        return GitRepository::query()
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(static fn (GitRepository $repository): array => [
                'id' => $repository->id,
                'full_name' => $repository->full_name,
            ])
            ->all();
    }
}
