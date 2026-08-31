<?php

namespace App\Enums\GIT;

/**
 * The kind of work a delivered task represents.
 *
 * Deliberately small and mutually exclusive: the value of the monthly report comes
 * from being able to say "this developer shipped four features and eight bug fixes",
 * which stops working once the vocabulary grows fuzzy or overlapping.
 */
enum TaskType: string implements \JsonSerializable
{
    case Feature = 'feature';
    case BugFix = 'bugfix';
    case Refactor = 'refactor';
    case Performance = 'performance';
    case Security = 'security';
    case Test = 'test';
    case Documentation = 'documentation';
    case Chore = 'chore';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Feature => 'Feature',
            self::BugFix => 'Bug fix',
            self::Refactor => 'Refactor',
            self::Performance => 'Performance',
            self::Security => 'Security',
            self::Test => 'Tests',
            self::Documentation => 'Documentation',
            self::Chore => 'Chore',
        };
    }

    /**
     * Whether the task adds user-visible capability, as opposed to maintaining what
     * is already there. Used by the report to separate output from upkeep.
     */
    public function isProductWork(): bool
    {
        return match ($this) {
            self::Feature, self::BugFix, self::Performance, self::Security => true,
            self::Refactor, self::Test, self::Documentation, self::Chore => false,
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
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
