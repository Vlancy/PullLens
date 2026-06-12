<?php

namespace App\Enums\GIT;

enum PullRequestFileStatus: string implements \JsonSerializable
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
    case Copied = 'copied';

    public function label(): string
    {
        return match ($this) {
            self::Added => 'Added',
            self::Modified => 'Modified',
            self::Deleted => 'Deleted',
            self::Renamed => 'Renamed',
            self::Copied => 'Copied',
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
