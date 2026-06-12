<?php

namespace App\Enums\GIT;

enum ReviewVerdict: string implements \JsonSerializable
{
    case Approve = 'approve';
    case Comment = 'comment';
    case RequestChanges = 'request_changes';

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approved',
            self::Comment => 'Commented',
            self::RequestChanges => 'Changes requested',
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
