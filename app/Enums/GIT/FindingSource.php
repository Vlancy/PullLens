<?php

namespace App\Enums\GIT;

/**
 * What produced a finding: the AI review or the gitleaks secret scan.
 */
enum FindingSource: string implements \JsonSerializable
{
    case Ai = 'ai';
    case Gitleaks = 'gitleaks';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI review',
            self::Gitleaks => 'Secrets',
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
