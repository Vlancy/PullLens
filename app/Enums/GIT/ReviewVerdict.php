<?php

namespace App\Enums\GIT;

enum ReviewVerdict: string implements \JsonSerializable
{
    case Approve = 'approve';
    case Comment = 'comment';
    case RequestChanges = 'request_changes';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approved',
            self::Comment => 'Commented',
            self::RequestChanges => 'Changes requested',
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
