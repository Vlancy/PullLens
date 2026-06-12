<?php

namespace App\Enums\GIT;

enum ReviewTrigger: string implements \JsonSerializable
{
    case Auto    = 'auto';
    case Manual  = 'manual';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Auto    => 'Automatic',
            self::Manual  => 'Manual',
            self::Webhook => 'Webhook',
        };
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
