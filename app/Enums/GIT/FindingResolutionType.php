<?php

namespace App\Enums\GIT;

enum FindingResolutionType: string implements \JsonSerializable
{
    case FixConfirmed = 'fix_confirmed';
    case FixSubmitted = 'fix_submitted';
    case Acknowledged = 'acknowledged';
    case WontFix = 'wont_fix';
    case FalsePositive = 'false_positive';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::FixConfirmed => 'Fix confirmed',
            self::FixSubmitted => 'Fix submitted',
            self::Acknowledged => 'Acknowledged',
            self::WontFix => "Won't fix",
            self::FalsePositive => 'False positive',
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
     *      *
     *      * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
