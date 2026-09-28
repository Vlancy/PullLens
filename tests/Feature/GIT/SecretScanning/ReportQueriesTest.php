<?php

use App\Services\Reports\DailyActivityReportService;
use App\Services\Reports\DeveloperMetricsReportService;
use App\Services\Reports\LeaderboardReportService;
use App\Services\Reports\OverviewReportService;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Compile every query a report runs, without executing it.
 *
 * @return array<int, string>
 */
function reportSql(callable $run): array
{
    return array_map(static fn (array $q): string => $q['query'], DB::pretend($run));
}

/**
 * Invoke a private method directly, for compiling the SQL of queries that a report's
 * public entry point only reaches once an earlier query returns rows - which never
 * happens under DB::pretend(), since every query it "runs" comes back empty.
 */
function callPrivateMethod(object $object, string $method, array $args = []): mixed
{
    return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
}

$innerJoinOnReview = 'inner join "pull_request_reviews" as "rev" on "rev"."id" = "f"."pull_request_review_id"';

it('counts overview findings without requiring a review', function () use ($innerJoinOnReview) {
    $sql = reportSql(fn () => app(OverviewReportService::class)->handle(ReportPeriod::AllTime));

    expect(collect($sql)->filter(fn ($s) => str_contains($s, $innerJoinOnReview)))->toBeEmpty();
});

it('counts leaderboard findings without requiring a review', function () use ($innerJoinOnReview) {
    // handle() only reaches findingTotals() once pullRequestTotals() returns rows, which it
    // never does under DB::pretend(); the private method is compiled directly instead.
    $service = app(LeaderboardReportService::class);
    $sql = reportSql(fn () => callPrivateMethod($service, 'findingTotals', [null, ['octocat']]));

    expect($sql)->not->toBeEmpty()
        ->and(collect($sql)->filter(fn ($s) => str_contains($s, $innerJoinOnReview)))->toBeEmpty();
});

it('dates daily findings by review when there is one and by creation otherwise', function () use ($innerJoinOnReview) {
    $sql = collect(reportSql(fn () => app(DailyActivityReportService::class)->handle(ReportPeriod::LastWeek)))
        ->first(fn ($s) => str_contains($s, 'critical_findings'));

    expect($sql)->not->toBeNull()
        ->and($sql)->not->toContain($innerJoinOnReview)
        ->and($sql)->toContain('left join "pull_request_reviews" as "rev"')
        ->and($sql)->toContain('COALESCE(rev.reviewed_at, f.created_at)');
});

it('scores developer seniority on ai findings only', function () {
    // handle() only reaches findingBreakdownByAuthor() once authorTotals() returns rows,
    // which it never does under DB::pretend(); the private method is compiled directly instead.
    $service = app(DeveloperMetricsReportService::class);
    $sql = collect(reportSql(fn () => callPrivateMethod($service, 'findingBreakdownByAuthor', [null, null])))
        ->first(fn ($s) => str_contains($s, '"f"."id" as "finding_id"'));

    expect($sql)->not->toBeNull()->and($sql)->toContain('"f"."source" = \'ai\'');
});
