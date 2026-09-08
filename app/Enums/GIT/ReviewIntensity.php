<?php

namespace App\Enums\GIT;

enum ReviewIntensity: string implements \JsonSerializable
{
    case Light = 'light';
    case Balanced = 'balanced';
    case Strict = 'strict';

    /**
     * Return the human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light - summary only',
            self::Balanced => 'Balanced - standard review',
            self::Strict => 'Strict - deep analysis',
        };
    }

    /**
     * Serialize as the backing string value for JSON responses and Inertia props.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Return all backing values for validation and select options.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
