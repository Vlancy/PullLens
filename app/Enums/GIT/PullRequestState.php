<?php

namespace App\Enums\GIT;

enum PullRequestState: string implements \JsonSerializable
{
    case Open = 'open';
    case Closed = 'closed';
    case Merged = 'merged';
    case Draft = 'draft';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Merged => 'Merged',
            self::Draft => 'Draft',
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
