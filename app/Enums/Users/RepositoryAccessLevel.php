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

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
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
