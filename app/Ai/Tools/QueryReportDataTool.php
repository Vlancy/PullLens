<?php

namespace App\Ai\Tools;

use App\Services\Reports\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QueryReportDataTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Fetches live metrics from PullLens. Call this before answering any question about numbers, performance, efficiency, trends, or developer activity.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reports = app(ReportService::class);

        $action = (string) $request->string('action');
        $period = (string) ($request->string('period') ?: 'all');
        $developer = $request->has('developer') ? trim((string) $request->string('developer')) : null;

        $data = match ($action) {
            'team_overview' => $reports->overview(),
            'developer_stats' => $reports->developers($period),
            'repositories' => $reports->repositories(),
            'commit_quality' => $reports->commits($period),
            'daily_activity' => $reports->daily($period === 'all' ? '30d' : $period),
            'developer_daily' => $reports->developerDaily($period === 'all' ? '7d' : $period),
            default => ['error' => "Unknown action: {$action}"],
        };

        if ($developer !== null && is_array($data) && isset($data[0])) {
            $data = array_values(array_filter($data, fn ($row) => isset($row['author_login'])
                && str_contains(strtolower((string) $row['author_login']), strtolower($developer))
                || isset($row['author_name'])
                && str_contains(strtolower((string) $row['author_name']), strtolower($developer))));
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['team_overview', 'developer_stats', 'repositories', 'commit_quality', 'daily_activity', 'developer_daily'])
                ->description('The type of report data to fetch.')
                ->required(),
            'period' => $schema->string()
                ->enum(['all', '7d', '30d', '90d', '1y'])
                ->description('Time period filter. Use "all" for all-time data. Defaults to "all".'),
            'developer' => $schema->string()
                ->description('Optional: filter results to a specific developer by login or name substring.')
                ->nullable(),
        ];
    }
}
