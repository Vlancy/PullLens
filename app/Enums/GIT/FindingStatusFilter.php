<?php

namespace App\Enums\GIT;

use App\Models\GIT\PullRequestReviewFinding;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolution states the findings list can be filtered by.
 *
 * Owning the query clause here keeps "what open means" in one place instead of
 * spread across `when()` chains in the controller.
 */
enum FindingStatusFilter: string implements \JsonSerializable
{
    case Open = 'open';
    case Resolved = 'resolved';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
            self::All => 'All',
        };
    }

    /**
     * Apply this status to a findings query.
     *
     * @param  Builder<PullRequestReviewFinding>  $query
     * @return Builder<PullRequestReviewFinding>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            self::Open => $query->whereNull('resolved_at'),
            self::Resolved => $query->whereNotNull('resolved_at'),
            self::All => $query,
        };
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
