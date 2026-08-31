<?php

use App\Support\Reports\ReportPeriod;

test('an unknown period falls back to the caller default', function () {
    expect(ReportPeriod::fromRequest('not-a-period', ReportPeriod::LastMonth))
        ->toBe(ReportPeriod::LastMonth);
});

test('a missing period falls back to the caller default', function () {
    expect(ReportPeriod::fromRequest(null, ReportPeriod::Today))->toBe(ReportPeriod::Today);
});

test('a known period is honoured', function () {
    expect(ReportPeriod::fromRequest('7d', ReportPeriod::AllTime))->toBe(ReportPeriod::LastWeek);
});

test('all time is unbounded', function () {
    expect(ReportPeriod::AllTime->startsAt())->toBeNull()
        ->and(ReportPeriod::AllTime->days())->toBeNull();
});

test('bounded periods start at the beginning of a day', function (ReportPeriod $period) {
    expect($period->startsAt()->toTimeString())->toBe('00:00:00');
})->with([
    ReportPeriod::Today,
    ReportPeriod::LastWeek,
    ReportPeriod::LastMonth,
    ReportPeriod::LastQuarter,
]);
