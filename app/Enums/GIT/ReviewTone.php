<?php

namespace App\Enums\GIT;

enum ReviewTone: string implements \JsonSerializable
{
    case Professional = 'professional';
    case Friendly     = 'friendly';
    case Concise      = 'concise';
    case Detailed     = 'detailed';

    public function label(): string
    {
        return match ($this) {
            self::Professional => 'Professional',
            self::Friendly     => 'Friendly',
            self::Concise      => 'Concise',
            self::Detailed     => 'Detailed',
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
