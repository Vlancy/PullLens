<?php

namespace App\Enums\GIT;

use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Database\Eloquent\Builder;

/**
 * Orderings offered by the findings list.
 *
 * Sorting is expressed as an enum rather than as a request-supplied column name so
 * no caller can inject an arbitrary ORDER BY expression.
 */
enum FindingSortOption: string implements \JsonSerializable
{
    case Severity = 'severity';
    case Date = 'date';
    case Category = 'category';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Severity => 'Severity',
            self::Date => 'Newest first',
            self::Category => 'Category',
        };
    }

    /**
     * Apply this ordering to a findings query.
     *
     * @param  Builder<PullRequestReviewFinding>  $query
     * @return Builder<PullRequestReviewFinding>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            self::Date => $query->orderByDesc('created_at'),
            self::Category => $query->orderBy('category')->orderByRaw(self::severityRanking()),
            self::Severity => $query->orderByRaw(self::severityRanking())->orderByDesc('created_at'),
        };
    }

    /**
     * SQL ordering that ranks severities by seriousness rather than alphabetically.
     *
     * Built from the enum's own case order, so adding a severity does not require
     * editing a hand-maintained CASE expression.
     */
    private static function severityRanking(): string
    {
        $clauses = [];

        // Values come from the enum, never from a request, so literal interpolation
        // here cannot carry user input into the query.
        foreach (FindingSeverity::cases() as $index => $severity) {
            $clauses[] = sprintf("WHEN '%s' THEN %d", $severity->value, $index + 1);
        }

        return sprintf(
            'CASE severity %s ELSE %d END',
            implode(' ', $clauses),
            count(FindingSeverity::cases()) + 1,
        );
    }

    /**
     * Serialize as the backing string value, so the enum crosses the wire
     * as a plain scalar rather than an object the front end must unwrap.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * All backing values, for validation rules and "in" comparisons.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
