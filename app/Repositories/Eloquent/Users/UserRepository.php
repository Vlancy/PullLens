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
    protected string $model = User::class;

    /**
     * Paginate users, optionally filtering by name or email keyword.
     *
     * @return LengthAwarePaginator<User>
     */
    public function search(string $keyword = '', int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()
            ->when($keyword, fn ($q) => $q->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            }))
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }
}
