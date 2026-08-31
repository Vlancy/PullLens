<?php

namespace App\Enums\GIT;

enum ReviewTrigger: string implements \JsonSerializable
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Webhook = 'webhook';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Manual => 'Manual',
            self::Webhook => 'Webhook',
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
