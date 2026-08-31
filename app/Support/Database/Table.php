<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a model's table name for use in joins and raw expressions.
 *
 * Queries that need an explicit table name go through here rather than hard-coding
 * the string, so renaming a table stays a change to the model alone.
 */
final class Table
{
    /** @var array<class-string<Model>, string> */
    private static array $cache = [];

    /**
     * The table backing the given model.
     *
     * @param  class-string<Model>  $model
     */
    public static function of(string $model): string
    {
        return self::$cache[$model] ??= (new $model)->getTable();
    }

    /**
     * The table name with a query alias appended, e.g. "pull_requests as pr".
     *
     * @param  class-string<Model>  $model
     */
    public static function as(string $model, string $alias): string
    {
        return self::of($model).' as '.$alias;
    }
}
