<?php

namespace App\Repositories\Eloquent\Users;

use App\Models\Users\User;
use App\Repositories\Contracts\Users\UserRepositoryInterface;
use App\Repositories\Eloquent\BaseRepository;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<User>
 */
class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    /** Hard ceiling on page size so a crafted request cannot ask for the whole table. */
    private const MAX_PER_PAGE = 100;

    protected string $model = User::class;

    /**
     * Paginate users, optionally filtering by name or email keyword.
     *
     * Wildcard escaping lives in the User::scopeSearch() query scope so every
     * caller gets the same, safe matching semantics.
     *
     * @return LengthAwarePaginator<User>
     */
    public function search(string $keyword = '', int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()
            // Roles drive the badge; repositories drive the per-repository grant editor.
            ->with(['roles:id,name', 'repositories:id,full_name'])
            ->search($keyword)
            ->orderBy('name')
            ->paginate(min(max($perPage, 1), self::MAX_PER_PAGE))
            ->withQueryString();
    }
}
