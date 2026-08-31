<?php

use App\Support\Reports\LowEffortCommitRule;

/*
| The same rule is evaluated in PHP and in SQL. These tests pin the PHP side and
| assert the SQL expression stays derived from the same word list, so the two cannot
| drift apart the way they had before the rule was centralised.
*/

test('very short messages are low effort', function (string $message) {
    expect(LowEffortCommitRule::matches($message))->toBeTrue();
})->with(['', 'x', 'wip', 'fix', '   a  ']);

test('placeholder words are low effort regardless of case', function (string $message) {
    expect(LowEffortCommitRule::matches($message))->toBeTrue();
})->with(['WIP', 'HotFix', 'quick fix', 'Initial', 'reverted']);

test('descriptive messages are not low effort', function (string $message) {
    expect(LowEffortCommitRule::matches($message))->toBeFalse();
})->with([
    'fix null pointer in token refresher',
    'Add webhook signature verification',
    'wip: extract review trigger policy',
]);

test('the SQL expression covers the same words as the PHP check', function () {
    $sql = LowEffortCommitRule::sqlCaseExpression('message');

    expect($sql)->toContain('wip')
        ->and($sql)->toContain('quick fix')
        ->and($sql)->toContain('LENGTH(TRIM(message))')
        ->and($sql)->toContain((string) LowEffortCommitRule::MIN_MEANINGFUL_LENGTH);
});
