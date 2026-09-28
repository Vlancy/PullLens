<?php

namespace App\Enums\GIT;

enum FindingResolutionType: string implements \JsonSerializable
{
    case FixConfirmed = 'fix_confirmed';
    case FixSubmitted = 'fix_submitted';
    case Acknowledged = 'acknowledged';
    case WontFix = 'wont_fix';
    case FalsePositive = 'false_positive';
    case SecretRemoved = 'secret_removed';

    /**
     * Human-readable name for this case, shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::FixConfirmed => 'Fix confirmed',
            self::FixSubmitted => 'Fix submitted',
            self::Acknowledged => 'Acknowledged',
            self::WontFix => "Won't fix",
            self::FalsePositive => 'False positive',
            self::SecretRemoved => 'Secret removed from diff',
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

    /**
     * The reasons a person may pick; SecretRemoved is set only by a rescan.
     *
     * @return array<int, self>
     */
    public static function manualCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type !== self::SecretRemoved));
    }

    /**
     * Backing values of the manual reasons, for validating a manual resolution.
     *
     * @return array<int, string>
     */
    public static function manualValues(): array
    {
        return array_column(self::manualCases(), 'value');
    }

    /**
     * Whether this resolution should keep a secret finding resolved on a later scan.
     *
     * FalsePositive, WontFix and Acknowledged are a human's judgement call about this
     * specific hit, so they hold. FixSubmitted and FixConfirmed both claim the secret
     * is already gone from the diff; if the same secret is still there on the next
     * scan, that claim did not hold up, so it must reopen as a fresh finding rather
     * than stay silently resolved. SecretRemoved is not a dismissal at all - a scan
     * sets it when the secret merely left the diff - so it reopens the same way.
     */
    public function dismissesSecret(): bool
    {
        return match ($this) {
            self::FalsePositive, self::WontFix, self::Acknowledged => true,
            self::FixSubmitted, self::FixConfirmed, self::SecretRemoved => false,
        };
    }
}
