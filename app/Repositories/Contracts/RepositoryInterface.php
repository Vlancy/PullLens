<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    /**
     * Return the Eloquent model class managed by this repository.
     */
    public function getModelClass(): string;

    /**
     * Fetch a collection of records using common repository constraints.
     *
     * @param  array<int, string>  $relations
     * @param  array<int, string>  $counts
     * @param  array<int, string>  $columns
     * @param  array{field?: string, direction?: string}  $sort
     */
    public function all(
        ?string $keyword = null,
        array $filters = [],
        array $relations = [],
        array $counts = [],
        array $columns = [],
        array $sort = [],
        ?int $limit = null,
    ): Collection;

    /**
     * Find a single model by its primary key.
     */
    public function find(string|int $id): ?Model;

    /**
     * Persist a new model with the supplied attributes.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Model;

    /**
     * Update an existing model with the supplied attributes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Model $model, array $data): Model;

    /**
     * Delete an existing model instance.
     */
    public function delete(Model $model): ?bool;
}
