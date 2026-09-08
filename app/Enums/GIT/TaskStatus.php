<?php

namespace App\Enums\GIT;

/**
 * Where a task stands in its life.
 *
 * Every value is derived from evidence PullLens already holds - whether the
 * delivering pull request merged, and whether later tasks point back at this one -
 * rather than from anyone remembering to update a field. That is what makes the
 * "was it right first time" question answerable at all.
 *
 * Precedence when several could apply is defined by {@see rank()}: a task that was
 * reverted *and* reworked reports as reverted, because that is the worse outcome and
 * the one an operator needs to see.
 */
enum TaskStatus: string implements \JsonSerializable
{
    /** Identified on a pull request that has not merged yet. */
    case InProgress = 'in_progress';

    /** Shipped, and nothing has come back to it since. */
    case Delivered = 'delivered';

    /** Shipped, then extended or changed by later work. */
    case Revised = 'revised';

    /** Shipped, then a later task had to fix a bug in it. */
    case Reworked = 'reworked';

    /** Shipped, then taken back out. */
    case Reverted = 'reverted';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::Delivered => 'Delivered',
            self::Revised => 'Revised',
            self::Reworked => 'Reworked',
            self::Reverted => 'Reverted',
        };
    }

    /**
     * Whether the task shipped at all. Used to keep unmerged work out of delivery
     * reporting without having to enumerate the shipped states at each call site.
     */
    public function isDelivered(): bool
    {
        return $this !== self::InProgress;
    }

    /**
     * Whether the task shipped and nothing has come back to it.
     *
     * This is the "done right the first time" signal.
     */
    public function isCleanDelivery(): bool
    {
        return $this === self::Delivered;
    }

    /**
     * Severity ordering, so the worst applicable outcome wins.
     */
    public function rank(): int
    {
        return match ($this) {
            self::InProgress => 0,
            self::Delivered => 1,
            self::Revised => 2,
            self::Reworked => 3,
            self::Reverted => 4,
        };
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

    /**
     * Value and label pairs for populating a select control.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
