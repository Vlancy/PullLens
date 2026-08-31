<?php

namespace App\Support\Reports;

use Carbon\CarbonInterface;

/**
 * The time windows every report can be scoped to.
 *
 * Replaces the loose period strings that were previously re-interpreted with a
 * `match` in each report method — a duplication that let the same label mean
 * different things on different pages. Requests validate against {@see values()}
 * and services receive the resolved enum, so an unknown value can never reach a query.
 */
enum ReportPeriod: string implements \JsonSerializable
{
    case Today = 'today';
    case LastWeek = '7d';
    case LastMonth = '30d';
    case LastQuarter = '90d';
    case ThisCalendarMonth = 'this_month';
    case PreviousCalendarMonth = 'last_month';
    case AllTime = 'all';

    /**
     * Human-readable name for this window, shown in the period selector.
     */
    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::LastWeek => 'Last 7 days',
            self::LastMonth => 'Last 30 days',
            self::LastQuarter => 'Last 90 days',
            self::ThisCalendarMonth => 'This month',
            self::PreviousCalendarMonth => 'Last month',
            self::AllTime => 'All time',
        };
    }

    /**
     * The inclusive lower bound for this window, or null when unbounded.
     */
    public function startsAt(): ?CarbonInterface
    {
        return match ($this) {
            self::Today => now()->startOfDay(),
            self::LastWeek => now()->subDays(7)->startOfDay(),
            self::LastMonth => now()->subDays(30)->startOfDay(),
            self::LastQuarter => now()->subDays(90)->startOfDay(),
            self::ThisCalendarMonth => now()->startOfMonth(),
            self::PreviousCalendarMonth => now()->subMonthNoOverflow()->startOfMonth(),
            self::AllTime => null,
        };
    }

    /**
     * The inclusive upper bound, or null when the window runs up to now.
     *
     * Only the calendar-month windows are closed at the far end; the rolling windows
     * are all "the last N days up to this moment".
     */
    public function endsAt(): ?CarbonInterface
    {
        return match ($this) {
            self::PreviousCalendarMonth => now()->subMonthNoOverflow()->endOfMonth(),
            default => null,
        };
    }

    /**
     * Number of whole days the window spans, or null when unbounded.
     *
     * Used by the day-by-day reports, which need a fixed row count rather than a
     * lower bound.
     */
    public function days(): ?int
    {
        return match ($this) {
            self::Today => 1,
            self::LastWeek => 7,
            self::LastMonth => 30,
            self::LastQuarter => 90,
            // Calendar months vary in length, so the span is measured rather than fixed.
            self::ThisCalendarMonth => (int) now()->startOfMonth()->diffInDays(now()) + 1,
            self::PreviousCalendarMonth => now()->subMonthNoOverflow()->daysInMonth,
            self::AllTime => null,
        };
    }

    /**
     * Resolve a raw request value, falling back to the supplied default.
     *
     * Report pages differ in what "no filter" should mean — the overview defaults to
     * today, the developer table to all time — so the default is the caller's choice.
     */
    public static function fromRequest(mixed $value, self $default): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? $default) : $default;
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
     * Serialize as the backing string value, so the enum crosses the wire
     * as a plain scalar rather than an object the front end must unwrap.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
