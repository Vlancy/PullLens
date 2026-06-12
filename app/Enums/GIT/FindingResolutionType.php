<?php

namespace App\Enums\GIT;

enum FindingResolutionType: string implements \JsonSerializable
{
    case FixConfirmed = 'fix_confirmed';
    case FixSubmitted = 'fix_submitted';
    case Acknowledged = 'acknowledged';
    case WontFix = 'wont_fix';
    case FalsePositive = 'false_positive';

    public function label(): string
    {
        return match ($this) {
            self::FixConfirmed => 'Fix confirmed',
            self::FixSubmitted => 'Fix submitted',
            self::Acknowledged => 'Acknowledged',
            self::WontFix => "Won't fix",
            self::FalsePositive => 'False positive',
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
