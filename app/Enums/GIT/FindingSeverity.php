<?php

namespace App\Enums\GIT;

enum FindingSeverity: string implements \JsonSerializable
{
    case Critical      = 'critical';
    case High          = 'high';
    case Medium        = 'medium';
    case Low           = 'low';
    case Informational = 'informational';

    public function label(): string
    {
        return match ($this) {
            self::Critical      => 'Critical',
            self::High          => 'High',
            self::Medium        => 'Medium',
            self::Low           => 'Low',
            self::Informational => 'Informational',
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
