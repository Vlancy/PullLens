<?php

namespace App\Enums\GIT;

enum MergeMethod: string implements \JsonSerializable
{
    case Merge  = 'merge';
    case Squash = 'squash';
    case Rebase = 'rebase';

    /**
     * Return the human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Merge  => 'Merge commit',
            self::Squash => 'Squash and merge',
            self::Rebase => 'Rebase and merge',
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
