<?php

namespace App\Enums\GIT;

/**
 * How one task relates to an earlier one.
 *
 * Directional: the relation always reads "this task <relation> that task", where
 * *this* is the newer task. Reversing the pair changes the meaning, so the link table
 * stores both ends explicitly rather than treating the pair as symmetric.
 */
enum TaskRelation: string implements \JsonSerializable
{
    /** The newer task repairs a defect in the earlier one. */
    case Fixes = 'fixes';

    /** The newer task builds on or changes the earlier one without it being broken. */
    case Extends = 'extends';

    /** The newer task removes the earlier one's change. */
    case Reverts = 'reverts';

    /** The newer task repeats work the earlier one already did. */
    case Duplicates = 'duplicates';

    /** Related, but not in one of the specific ways above. */
    case Relates = 'relates';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Fixes => 'Fixes',
            self::Extends => 'Extends',
            self::Reverts => 'Reverts',
            self::Duplicates => 'Duplicates',
            self::Relates => 'Relates to',
        };
    }

    /**
     * How this relation reads from the older task's side, for rendering its history.
     */
    public function inverseLabel(): string
    {
        return match ($this) {
            self::Fixes => 'Fixed by',
            self::Extends => 'Extended by',
            self::Reverts => 'Reverted by',
            self::Duplicates => 'Duplicated by',
            self::Relates => 'Related to',
        };
    }

    /**
     * The status this relation forces onto the *earlier* task.
     *
     * Null means the relation carries no lifecycle consequence — a duplicate or a
     * loose association says nothing about whether the original work was sound.
     */
    public function impliedStatusForTarget(): ?TaskStatus
    {
        return match ($this) {
            self::Fixes => TaskStatus::Reworked,
            self::Extends => TaskStatus::Revised,
            self::Reverts => TaskStatus::Reverted,
            self::Duplicates, self::Relates => null,
        };
    }

    /**
     * Whether this relation means the earlier task was not right first time.
     */
    public function indicatesRework(): bool
    {
        return $this === self::Fixes || $this === self::Reverts;
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
            static fn (self $relation): array => ['value' => $relation->value, 'label' => $relation->label()],
            self::cases(),
        );
    }
}
