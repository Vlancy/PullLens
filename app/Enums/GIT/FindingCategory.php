<?php

namespace App\Enums\GIT;

enum FindingCategory: string implements \JsonSerializable
{
    case Security = 'security';
    case Correctness = 'correctness';
    case Reliability = 'reliability';
    case Performance = 'performance';
    case Maintainability = 'maintainability';
    case Testing = 'testing';

    public function label(): string
    {
        return match ($this) {
            self::Security => 'Security',
            self::Correctness => 'Correctness',
            self::Reliability => 'Reliability',
            self::Performance => 'Performance',
            self::Maintainability => 'Maintainability',
            self::Testing => 'Testing',
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
