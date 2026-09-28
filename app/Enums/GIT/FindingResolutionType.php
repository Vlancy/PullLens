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
    case FixedInLaterPush = 'fixed_in_later_push';

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
            self::FixedInLaterPush => 'Fixed in a later push',
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
     * The reasons a person may pick; SecretRemoved and FixedInLaterPush are set only by a rescan.
     *
     * @return array<int, self>
     */
    public static function manualCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => ! in_array($type, [self::SecretRemoved, self::FixedInLaterPush], true),
        ));
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
     * Whether this resolution should keep a scanner finding resolved on a later scan.
     *
     * FalsePositive, WontFix and Acknowledged are a human's judgement call about this
     * specific hit, so they hold. FixSubmitted and FixConfirmed both claim the problem
     * is gone; if the same problem is still in the diff on the next scan, that claim
     * did not hold up, so it reopens as a fresh finding. SecretRemoved and
     * FixedInLaterPush are not dismissals at all - a scan sets them when the problem
     * merely left the diff - so they reopen the same way.
     */
    public function staysDismissed(): bool
    {
        return match ($this) {
            self::FalsePositive, self::WontFix, self::Acknowledged => true,
            self::FixSubmitted, self::FixConfirmed, self::SecretRemoved, self::FixedInLaterPush => false,
        };
    }
}
