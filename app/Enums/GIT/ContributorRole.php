<?php

namespace App\Enums\GIT;

enum ContributorRole: string implements \JsonSerializable
{
    case Author = 'author';
    case CoAuthor = 'co_author';
    case Reviewer = 'reviewer';
    case Commenter = 'commenter';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Author => 'Author',
            self::CoAuthor => 'Co-author',
            self::Reviewer => 'Reviewer',
            self::Commenter => 'Commenter',
        };
    }

    /**
     * Serialize as the backing string value, so the enum crosses the wire
     * as a plain scalar rather than an object the front end must unwrap.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * All backing values, for validation rules and "in" comparisons.
     *      *
     *      * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
