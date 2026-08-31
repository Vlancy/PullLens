<?php

namespace App\Support\Reports;

/**
 * The scored outcome of assessing one developer's review history.
 *
 * `score` and `level` are null when the sample is too small to be meaningful; the
 * UI renders that as "not enough data" rather than as a low score.
 */
final readonly class SeniorityAssessment
{
    public function __construct(
        public ?float $score,
        public ?string $level,
        public ?float $resolutionRate,
    ) {}
}
