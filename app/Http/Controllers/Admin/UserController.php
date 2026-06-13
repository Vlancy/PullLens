<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Users\User;
use App\Repositories\Contracts\Users\UserRepositoryInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request, UserRepositoryInterface $users): Response
    {
        $search = $request->string('search')->trim()->value();

        $paginator = $users->search($search);

        $items = collect($paginator->items())->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
            'update_url' => route('admin.users.update', $user->id),
            'destroy_url' => route('admin.users.destroy', $user->id),
        ]);

        return Inertia::render('admin/users', [
            'users' => $items,
            'store_url' => route('admin.users.store'),
            'filters' => ['search' => $search],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request, UserRepositoryInterface $users): RedirectResponse
    {
        $user = $users->create($request->validated());
        $user->forceFill(['email_verified_at' => now()])->save();

        return to_route('admin.users.index')->with('status', 'User created.');
    }

    public function update(UpdateUserRequest $request, User $user, UserRepositoryInterface $users): RedirectResponse
    {
        abort_if($user->id === Auth::id(), 403, 'You cannot edit your own account here.');

        $data = $request->validated();

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $users->update($user, $data);

        return to_route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(User $user, UserRepositoryInterface $users): RedirectResponse
    {
        abort_if($user->id === Auth::id(), 403, 'You cannot delete your own account.');

        $users->delete($user);

        return to_route('admin.users.index')->with('status', 'User deleted.');
    }
}
