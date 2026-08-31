<?php

namespace App\Enums\GIT;

enum FindingCategory: string implements \JsonSerializable
{
    case Security = 'security';
    case Correctness = 'correctness';
    case Reliability = 'reliability';
    case Performance = 'performance';
    case Maintainability = 'maintainability';
    case Testing = 'testing';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Security => 'Security',
            self::Correctness => 'Correctness',
            self::Reliability => 'Reliability',
            self::Performance => 'Performance',
            self::Maintainability => 'Maintainability',
            self::Testing => 'Testing',
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
