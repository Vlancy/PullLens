<?php

namespace App\Enums\GIT;

enum CommentSuggestedResolution: string implements \JsonSerializable
{
    case Resolve = 'resolve';
    case KeepOpen = 'keep_open';
    case Escalate = 'escalate';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Resolve => 'Resolve thread',
            self::KeepOpen => 'Keep open',
            self::Escalate => 'Escalate',
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
