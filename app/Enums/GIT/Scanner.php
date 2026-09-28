<?php

namespace App\Enums\GIT;

/**
 * A security scanner PullLens runs against pull requests, and everything that
 * differs between them: the GitHub check it reports on, its git notes ref, its log
 * keys and the finding source its results carry.
 */
enum Scanner: string
{
    case Gitleaks = 'gitleaks';
    case Trivy = 'trivy';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Gitleaks => 'Secrets',
            self::Trivy => 'Vulnerabilities',
        };
    }

    /**
     * Name of the GitHub check run this scanner reports on.
     */
    public function checkName(): string
    {
        return match ($this) {
            self::Gitleaks => 'PullLens / Secrets',
            self::Trivy => 'PullLens / Vulnerabilities',
        };
    }

    /**
     * The git notes ref, without "refs/", this scanner writes its record to.
     */
    public function notesRef(): string
    {
        return match ($this) {
            self::Gitleaks => 'notes/gitleaks',
            self::Trivy => 'notes/trivy',
        };
    }

    /**
     * Prefix of every log message this scanner writes.
     */
    public function logPrefix(): string
    {
        return match ($this) {
            self::Gitleaks => 'secret_scan',
            self::Trivy => 'vulnerability_scan',
        };
    }

    /**
     * The finding source this scanner's findings are stored under.
     */
    public function findingSource(): FindingSource
    {
        return match ($this) {
            self::Gitleaks => FindingSource::Gitleaks,
            self::Trivy => FindingSource::Trivy,
        };
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
