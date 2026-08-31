<?php

namespace App\Support\Reports;

use Illuminate\Support\Collection;

/**
 * Builds gap-free date series from sparse grouped-by-date query results.
 *
 * Charts need one point per day (or per week) even when nothing happened, and every
 * report was previously re-implementing that fill with its own `collect(range(...))`
 * loop. Centralising it removes the duplication and the off-by-one risk that came
 * with it.
 */
final class DateSeries
{
    /**
     * One entry per day, oldest first, ending today.
     *
     * @param  Collection<string, mixed>  $rows  Query results keyed by `Y-m-d`.
     * @param  callable(mixed, string): array<string, mixed>  $format  Maps a row (or null) plus its date to an output entry.
     * @return array<int, array<string, mixed>>
     */
    public static function daily(int $days, Collection $rows, callable $format): array
    {
        return Collection::make(range($days - 1, 0))
            ->map(function (int $offset) use ($rows, $format): array {
                $date = now()->subDays($offset)->format('Y-m-d');

                return $format($rows->get($date), $date);
            })
            ->values()
            ->all();
    }

    /**
     * One entry per ISO week, oldest first, ending with the current week.
     *
     * @param  Collection<string, mixed>  $rows  Query results keyed by the week's start date (`Y-m-d`).
     * @param  callable(mixed, string): array<string, mixed>  $format
     * @return array<int, array<string, mixed>>
     */
    public static function weekly(int $weeks, Collection $rows, callable $format): array
    {
        return Collection::make(range($weeks - 1, 0))
            ->map(function (int $offset) use ($rows, $format): array {
                $week = now()->startOfWeek()->subWeeks($offset)->format('Y-m-d');

                return $format($rows->get($week), $week);
            })
            ->values()
            ->all();
    }
}
