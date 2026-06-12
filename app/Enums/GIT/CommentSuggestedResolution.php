<?php

namespace App\Enums\GIT;

enum CommentSuggestedResolution: string implements \JsonSerializable
{
    case Resolve = 'resolve';
    case KeepOpen = 'keep_open';
    case Escalate = 'escalate';

    public function label(): string
    {
        return match ($this) {
            self::Resolve => 'Resolve thread',
            self::KeepOpen => 'Keep open',
            self::Escalate => 'Escalate',
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
