<?php

namespace App\Enums\GIT;

/**
 * Who produced a piece of derived data: the AI, or a person correcting it.
 *
 * Manual values are never overwritten by AI.
 */
enum AttributionSource: string implements \JsonSerializable
{
    /** Produced automatically by the AI. */
    case Ai = 'ai';

    /** Entered or corrected by a person. */
    case Manual = 'manual';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI',
            self::Manual => 'Manual',
        };
    }

    /**
     * Serialize as the backing string value.
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
            static fn (self $source): array => ['value' => $source->value, 'label' => $source->label()],
            self::cases(),
        );
    }
}
