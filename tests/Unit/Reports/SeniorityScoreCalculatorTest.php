<?php

use App\Support\Reports\SeniorityScoreCalculator;

/*
| The seniority score drives how developers are compared, so its edge cases are
| pinned here rather than being inferred from a rendered report page.
*/

beforeEach(function () {
    $this->calculator = new SeniorityScoreCalculator;
});

test('a sample below the minimum review count yields no score', function () {
    $result = $this->calculator->assess(['critical' => 0], 0, 2, 0);

    expect($result->score)->toBeNull()
        ->and($result->level)->toBeNull();
});

test('a clean record with enough reviews scores at the top', function () {
    $result = $this->calculator->assess([], 0, 10, 0);

    expect($result->score)->toBe(100.0)
        ->and($result->level)->toBe('Expert');
});

test('critical findings weigh far more than low ones', function () {
    $withCriticals = $this->calculator->assess(['critical' => 3], 3, 10, 0)->score;
    $withLows = $this->calculator->assess(['low' => 3], 3, 10, 0)->score;

    expect($withCriticals)->toBeLessThan($withLows);
});

test('unresolved findings lower the score relative to resolved ones', function () {
    $resolved = $this->calculator->assess(['medium' => 4], 4, 10, 0)->score;
    $unresolved = $this->calculator->assess(['medium' => 4], 0, 10, 0)->score;

    expect($unresolved)->toBeLessThan($resolved);
});

test('the score is never negative no matter how bad the record', function () {
    $result = $this->calculator->assess(['critical' => 500], 0, 3, 3);

    expect($result->score)->toBeGreaterThanOrEqual(0.0)
        ->and($result->level)->toBe('Junior');
});

test('resolution rate is null when there were no real findings', function () {
    expect($this->calculator->assess([], 0, 10, 0)->resolutionRate)->toBeNull();
});

test('resolution rate is reported even when the score is withheld', function () {
    $result = $this->calculator->assess(['low' => 4], 2, 1, 0);

    expect($result->score)->toBeNull()
        ->and($result->resolutionRate)->toBe(50.0);
});
