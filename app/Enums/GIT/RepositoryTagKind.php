<?php

namespace App\Enums\GIT;

/**
 * What a repository profile tag describes.
 */
enum RepositoryTagKind: string implements \JsonSerializable
{
    /** A programming language. */
    case Language = 'language';

    /** A framework or major library. */
    case Framework = 'framework';

    /** The business or product domain. */
    case Domain = 'domain';

    /** Anything that fits none of the above. */
    case Other = 'other';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Language => 'Language',
            self::Framework => 'Framework',
            self::Domain => 'Domain',
            self::Other => 'Other',
        };
    }

    /**
     * Serialize as the backing string value.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * All backing values, for validation rules and "in" comparisons.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Value and label pairs for populating a select control.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            self::cases(),
        );
    }
}
