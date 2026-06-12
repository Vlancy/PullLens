<?php

namespace App\Enums\GIT;

enum PullRequestState: string implements \JsonSerializable
{
    case Open = 'open';
    case Closed = 'closed';
    case Merged = 'merged';
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Merged => 'Merged',
            self::Draft => 'Draft',
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
