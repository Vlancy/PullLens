<?php

namespace App\Support\Reports;

/**
 * Scores a developer's code quality from their AI review history.
 *
 * Three weighted factors, each normalised to 0-100:
 *
 *   1. Finding rate (60%) — severity-weighted real findings per reviewed PR.
 *   2. Fix rate     (25%) — share of their real findings that were resolved.
 *   3. Verdict      (15%) — how often the reviewer demanded changes.
 *
 * Kept separate from the query layer so the weighting can be tuned and unit tested
 * without touching a database.
 */
final class SeniorityScoreCalculator
{
    /** Severity weights used to convert a finding mix into a single penalty figure. */
    private const SEVERITY_WEIGHTS = [
        'critical' => 4.0,
        'high' => 2.0,
        'medium' => 0.75,
        'low' => 0.25,
    ];

    /** Weighted findings per PR at which the quality factor bottoms out at zero. */
    private const RATE_CEILING = 3.0;

    private const WEIGHT_FINDINGS = 0.60;

    private const WEIGHT_RESOLUTION = 0.25;

    private const WEIGHT_VERDICT = 0.15;

    /** Below this many reviewed PRs the score is statistically meaningless. */
    private const MIN_REVIEWS_FOR_SCORE = 3;

    /** Neutral score used for the verdict factor when there are no reviews at all. */
    private const NEUTRAL_VERDICT_SCORE = 50.0;

    /**
     * @param  array<string, int>  $realFindingsBySeverity  Findings minus false positives, keyed by severity value.
     * @param  int  $resolvedRealFindings  Real findings the developer resolved.
     * @param  int  $reviewCount  PRs of theirs that were reviewed.
     * @param  int  $requestChangesCount  Reviews that demanded changes.
     */
    public function assess(
        array $realFindingsBySeverity,
        int $resolvedRealFindings,
        int $reviewCount,
        int $requestChangesCount,
    ): SeniorityAssessment {
        $totalReal = array_sum($realFindingsBySeverity);

        $resolutionRate = $totalReal > 0
            ? round(($resolvedRealFindings / $totalReal) * 100.0, 1)
            : null;

        // Fewer than a handful of reviews tells us nothing; report "no score" instead
        // of a number that would swing wildly with the next PR.
        if ($reviewCount < self::MIN_REVIEWS_FOR_SCORE) {
            return new SeniorityAssessment(null, null, $resolutionRate);
        }

        $score = ($this->qualityScore($realFindingsBySeverity, $reviewCount) * self::WEIGHT_FINDINGS)
            + ($this->resolutionScore($resolvedRealFindings, $totalReal) * self::WEIGHT_RESOLUTION)
            + ($this->verdictScore($requestChangesCount, $reviewCount) * self::WEIGHT_VERDICT);

        return new SeniorityAssessment(round($score, 1), $this->level($score), $resolutionRate);
    }

    /**
     * Factor 1: severity-weighted findings per reviewed PR, inverted and clamped.
     *
     * @param  array<string, int>  $realFindingsBySeverity
     */
    private function qualityScore(array $realFindingsBySeverity, int $reviewCount): float
    {
        $weighted = 0.0;

        foreach (self::SEVERITY_WEIGHTS as $severity => $weight) {
            $weighted += ($realFindingsBySeverity[$severity] ?? 0) * $weight;
        }

        $rate = $weighted / max(1, $reviewCount);

        return max(0.0, min(100.0, 100.0 * (1.0 - $rate / self::RATE_CEILING)));
    }

    /**
     * Factor 2: proportion of real findings resolved. No findings scores perfectly —
     * there was nothing to fix.
     */
    private function resolutionScore(int $resolved, int $totalReal): float
    {
        if ($totalReal <= 0) {
            return 100.0;
        }

        return min(100.0, ($resolved / $totalReal) * 100.0);
    }

    /**
     * Factor 3: inverse of how often the reviewer requested changes.
     */
    private function verdictScore(int $requestChangesCount, int $reviewCount): float
    {
        if ($reviewCount <= 0) {
            return self::NEUTRAL_VERDICT_SCORE;
        }

        return max(0.0, 100.0 - ($requestChangesCount / $reviewCount) * 100.0);
    }

    /**
     * Human-readable band for a numeric score.
     */
    private function level(float $score): string
    {
        return match (true) {
            $score >= 80 => 'Expert',
            $score >= 60 => 'Senior',
            $score >= 35 => 'Mid',
            default => 'Junior',
        };
    }
}
