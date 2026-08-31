<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * @var class-string<TModel>
     */
    protected string $model = Model::class;

    /**
     * Fetch a collection of records using common repository constraints.
     *
     * @param  array<int, string>  $relations
     * @param  array<int, string>  $counts
     * @param  array<int, string>  $columns
     * @param  array{field?: string, direction?: string}  $sort
     * @return Collection<int, TModel>
     */
    public function all(
        ?string $keyword = null,
        array $filters = [],
        array $relations = [],
        array $counts = [],
        array $columns = [],
        array $sort = [],
        ?int $limit = null,
    ): Collection {
        $query = $this->query();

        if (! empty($filters)) {
            foreach ($filters as $field => $value) {
                $query->where($field, $value);
            }
        }

        if (! empty($relations)) {
            $query->with($relations);
        }

        if (! empty($counts)) {
            $query->withCount($counts);
        }

        if (! empty($columns)) {
            $query->select($columns);
        }

        if (! empty($sort['field'])) {
            $query->orderBy($sort['field'], $sort['direction'] ?? 'asc');
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Find a single model by its primary key.
     *
     * @return TModel|null
     */
    public function find(string|int $id): ?Model
    {
        return $this->query()->find($id);
    }

    /**
     * Persist a new model with the supplied attributes.
     *
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function create(array $data): Model
    {
        return $this->query()->create($data);
    }

    /**
     * Update an existing model without requiring fillable reassignment in callers.
     *
     * @param  array<string, mixed>  $data
     * @return TModel
     */
    public function update(Model $model, array $data): Model
    {
        $model->forceFill($data)->save();

        return $model->refresh();
    }

    /**
     * Delete an existing model instance.
     */
    public function delete(Model $model): ?bool
    {
        return $model->delete();
    }

    /**
     * Return the Eloquent model class managed by this repository.
     *
     * @return class-string<TModel>
     */
    public function getModelClass(): string
    {
        return $this->model;
    }

    /**
     * Start a fresh Eloquent query for the managed model.
     *
     * @return Builder<TModel>
     */
    protected function query(): Builder
    {
        $model = $this->getModelClass();

        return $model::query();
    }
}
