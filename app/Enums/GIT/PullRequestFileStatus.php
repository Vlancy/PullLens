<?php

namespace App\Enums\GIT;

enum PullRequestFileStatus: string implements \JsonSerializable
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
    case Copied = 'copied';

    /**
     * Human-readable name for this case, shown in the interface.
     */
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
