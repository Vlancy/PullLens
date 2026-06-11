<?php

namespace App\Enums\GIT;

enum GitProvider: string
{
    case Github = 'github';

    /**
     * Return the human-readable provider name for UI messages.
     */
    public function label(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
        };
    }

    /**
     * Return OAuth scopes required for the initial account connection.
     *
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Github => ['read:user', 'user:email'],
        };
    }

    /**
     * Return supported provider values for validation and filters.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
