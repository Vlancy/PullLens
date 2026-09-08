<?php

use App\Services\Reports\DeveloperProfileReportService;
use Illuminate\Support\Facades\DB;

/*
| The developer profile report is written in PostgreSQL-only SQL (DATE_TRUNC,
| EXTRACT(EPOCH ...)), so the in-memory SQLite test database cannot execute it. The
| queries are compiled under DB::pretend() instead, which builds the SQL without
| running it, and asserted on as strings.
*/

/**
 * The SQL every query in the developer profile report compiles to.
 *
 * @return array<int, string>
 */
function developerProfileSql(): array
{
    $queries = DB::pretend(static function (): void {
        app(DeveloperProfileReportService::class)->handle('octo');
    });

    return array_map(static fn (array $query): string => $query['query'], $queries);
}

test('the recent pull requests query counts findings against the pr alias', function () {
    $recent = collect(developerProfileSql())
        ->first(static fn (string $sql): bool => str_contains($sql, 'from "pull_requests" as "pr"'));

    expect($recent)->not->toBeNull()
        // Correlating on the real table name instead of the alias makes PostgreSQL
        // reject the whole query: "invalid reference to FROM-clause entry".
        ->and($recent)->toContain('"pr"."id"')
        ->and($recent)->not->toContain('"pull_requests"."id"');
});
