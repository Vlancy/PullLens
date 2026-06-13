<?php

namespace App\Repositories\Contracts\Users;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * Paginate users, optionally filtering by name or email keyword.
     *
     * @return LengthAwarePaginator<\App\Models\Users\User>
     */
    public function search(string $keyword = '', int $perPage = 15): LengthAwarePaginator;
}
