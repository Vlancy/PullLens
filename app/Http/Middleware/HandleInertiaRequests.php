<?php

namespace App\Http\Middleware;

use App\Enums\Users\UserPermission;
use App\Models\Users\User;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $this->userPayload($user),
                'permissions' => $this->permissionPayload($user),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'telescope_enabled' => class_exists(Telescope::class) && ($user?->can('viewTelescope') ?? false),
            'horizon_enabled' => class_exists(Horizon::class) && ($user?->can('viewHorizon') ?? false),
            // The guide is withdrawn on an instance running with HOMEPAGE_LOGIN on,
            // so the sidebar must not offer a link that would 404.
            'docs_enabled' => ! config('pulllens.homepage_login'),
        ];
    }

    /**
     * Serialize only the attributes the front end actually renders.
     *
     * Sharing the model wholesale would ship every column - including columns added
     * by future migrations - to the browser on every request. Whitelisting keeps that
     * surface fixed and reviewable.
     *
     * @return array<string, mixed>|null
     */
    private function userPayload(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'roles' => $user->getRoleNames()->values()->all(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }

    /**
     * Expose permission flags so the UI can hide actions the user cannot perform.
     *
     * This is a presentation convenience only - every one of these is independently
     * enforced by route middleware and form request authorization on the server.
     *
     * @return array<string, bool>
     */
    private function permissionPayload(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $payload = [];

        foreach (UserPermission::cases() as $permission) {
            $payload[$permission->value] = $user->hasPermission($permission);
        }

        return $payload;
    }
}
