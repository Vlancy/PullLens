<?php

namespace App\Enums\GIT;

/**
 * What produced a finding: the AI review, the gitleaks secret scan or the Trivy vulnerability scan.
 */
enum FindingSource: string implements \JsonSerializable
{
    case Ai = 'ai';
    case Gitleaks = 'gitleaks';
    case Trivy = 'trivy';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI review',
            self::Gitleaks => 'Secrets',
            self::Trivy => 'Vulnerabilities',
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
     * The security scanners, whose findings belong on the Security page rather than the review list.
     *
     * @return array<int, self>
     */
    public static function scanners(): array
    {
        return [self::Gitleaks, self::Trivy];
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
