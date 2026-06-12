<?php

namespace App\Enums\GIT;

enum ContributorRole: string implements \JsonSerializable
{
    case Author    = 'author';
    case CoAuthor  = 'co_author';
    case Reviewer  = 'reviewer';
    case Commenter = 'commenter';

    public function label(): string
    {
        return match ($this) {
            self::Author    => 'Author',
            self::CoAuthor  => 'Co-author',
            self::Reviewer  => 'Reviewer',
            self::Commenter => 'Commenter',
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
