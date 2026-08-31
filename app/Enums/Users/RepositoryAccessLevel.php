<?php

namespace App\Enums\Users;

/**
 * How much a user may do with a repository they have been granted access to.
 *
 * Only relevant for users who lack the `repositories.view-all` permission; for
 * everyone else repository access is global and this pivot is never consulted.
 */
enum RepositoryAccessLevel: string implements \JsonSerializable
{
    /** Read the repository, its pull requests and its findings. */
    case View = 'view';

    /** Everything View allows, plus resolving findings and triggering reviews on it. */
    case Manage = 'manage';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::View => 'View only',
            self::Manage => 'View and manage',
        };
    }

    /**
     * Whether this level permits changing repository state.
     */
    public function allowsManagement(): bool
    {
        return $this === self::Manage;
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
            static fn (self $level): array => ['value' => $level->value, 'label' => $level->label()],
            self::cases(),
        );
    }
}
