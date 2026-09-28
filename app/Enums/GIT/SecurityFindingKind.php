<?php

namespace App\Enums\GIT;

/**
 * The tab a scanner finding belongs to on the Security page, stored as metadata.kind.
 */
enum SecurityFindingKind: string implements \JsonSerializable
{
    case Secret = 'secret';
    case Vulnerability = 'vulnerability';
    case Misconfiguration = 'misconfiguration';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Secret => 'Secrets',
            self::Vulnerability => 'Vulnerabilities',
            self::Misconfiguration => 'Misconfigurations',
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
}
