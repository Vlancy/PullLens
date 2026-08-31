<?php

namespace App\Services\AI;

use App\Enums\AI\AiOperation;
use App\Models\AI\AiUsageRecord;
use App\Support\Access\RepositoryScope;
use App\Support\Reports\DateSeries;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the AI usage ledger for the spend report.
 *
 * The report is built to answer one question first — where is the money going — so
 * every breakdown is ordered by cost rather than by call count. A thousand cheap
 * assistant messages matter less than fifty large reviews.
 */
class AiUsageReportService
{
    /** Rows in each "biggest consumers" table. */
    private const TOP_LIMIT = 10;

    /** Days in the trend chart. */
    private const TREND_DAYS = 30;

    /**
     * Headline totals for the period.
     *
     * @return array<string, mixed>
     */
    public function totals(ReportPeriod $period, RepositoryScope $scope): array
    {
        $row = $this->scoped($period, $scope)
            ->toBase()
            ->selectRaw('
                COUNT(*) as calls,
                COALESCE(SUM(total_tokens), 0) as tokens,
                COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                COALESCE(SUM(cache_read_tokens), 0) as cache_read_tokens,
                COALESCE(SUM(cost_usd), 0) as cost,
                COALESCE(AVG(duration_ms), 0) as avg_duration
            ')
            ->first();

        $calls = (int) ($row->calls ?? 0);
        $tokens = (int) ($row->tokens ?? 0);
        $cost = (float) ($row->cost ?? 0);

        return [
            'calls' => $calls,
            'tokens' => $tokens,
            'prompt_tokens' => (int) ($row->prompt_tokens ?? 0),
            'completion_tokens' => (int) ($row->completion_tokens ?? 0),
            'cache_read_tokens' => (int) ($row->cache_read_tokens ?? 0),
            'cost_usd' => round($cost, 4),
            'avg_tokens_per_call' => $calls > 0 ? (int) round($tokens / $calls) : 0,
            'avg_cost_per_call' => $calls > 0 ? round($cost / $calls, 4) : 0.0,
            'avg_duration_ms' => (int) round((float) ($row->avg_duration ?? 0)),
            // What share of input came from cache. The single clearest indicator of
            // whether prompt caching is actually working.
            'cache_hit_rate' => $this->cacheHitRate($row),
        ];
    }

    /**
     * Spend split by what triggered it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byOperation(ReportPeriod $period, RepositoryScope $scope): array
    {
        return $this->grouped($period, $scope, 'operation')
            ->map(static fn (object $row): array => [
                'operation' => $row->operation,
                'label' => AiOperation::tryFrom((string) $row->operation)?->label() ?? (string) $row->operation,
                'automatic' => AiOperation::tryFrom((string) $row->operation)?->isAutomatic() ?? false,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'cost_usd' => round((float) $row->cost, 4),
            ])
            ->all();
    }

    /**
     * Spend split by model, so an expensive default is obvious.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byModel(ReportPeriod $period, RepositoryScope $scope): array
    {
        return $this->grouped($period, $scope, 'model')
            ->map(static fn (object $row): array => [
                'model' => $row->model ?? 'unknown',
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'cost_usd' => round((float) $row->cost, 4),
                'avg_tokens' => (int) $row->calls > 0 ? (int) round($row->tokens / $row->calls) : 0,
            ])
            ->all();
    }

    /**
     * The repositories consuming the most, which is where tuning pays off.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byRepository(ReportPeriod $period, RepositoryScope $scope): array
    {
        return $this->scoped($period, $scope)
            ->toBase()
            ->join('git_repositories as gr', 'gr.id', '=', 'ai_usage_records.git_repository_id')
            ->select([
                'gr.full_name',
                DB::raw('COUNT(*) as calls'),
                DB::raw('COALESCE(SUM(ai_usage_records.total_tokens), 0) as tokens'),
                DB::raw('COALESCE(SUM(ai_usage_records.cost_usd), 0) as cost'),
            ])
            ->groupBy('gr.full_name')
            ->orderByDesc('cost')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(static fn (object $row): array => [
                'repository' => $row->full_name,
                'calls' => (int) $row->calls,
                'tokens' => (int) $row->tokens,
                'cost_usd' => round((float) $row->cost, 4),
            ])
            ->all();
    }

    /**
     * The individual calls that consumed the most, for spotting outliers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function largestCalls(ReportPeriod $period, RepositoryScope $scope): array
    {
        return $this->scoped($period, $scope)
            ->with(['repository:id,full_name', 'pullRequest:id,number,title,web_url'])
            ->orderByDesc('total_tokens')
            ->limit(self::TOP_LIMIT)
            ->get()
            ->map(static fn (AiUsageRecord $record): array => [
                'id' => $record->id,
                'operation' => $record->operation?->label(),
                'model' => $record->model,
                'tokens' => $record->total_tokens,
                'prompt_tokens' => $record->prompt_tokens,
                'completion_tokens' => $record->completion_tokens,
                'cost_usd' => $record->cost_usd === null ? null : round((float) $record->cost_usd, 4),
                'duration_ms' => $record->duration_ms,
                'created_at' => $record->created_at?->toISOString(),
                'repository' => $record->repository?->full_name,
                'pull_request' => $record->pullRequest === null ? null : [
                    'number' => $record->pullRequest->number,
                    'title' => $record->pullRequest->title,
                    'web_url' => $record->pullRequest->web_url,
                ],
            ])
            ->all();
    }

    /**
     * Daily tokens and cost, zero-filled so the chart has no gaps.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(RepositoryScope $scope): array
    {
        $rows = $this->baseQuery($scope)
            ->toBase()
            ->where('ai_usage_records.created_at', '>=', now()->subDays(self::TREND_DAYS - 1)->startOfDay())
            ->select([
                DB::raw('CAST(ai_usage_records.created_at AS DATE) as date'),
                DB::raw('COUNT(*) as calls'),
                DB::raw('COALESCE(SUM(total_tokens), 0) as tokens'),
                DB::raw('COALESCE(SUM(cost_usd), 0) as cost'),
            ])
            ->groupBy(DB::raw('CAST(ai_usage_records.created_at AS DATE)'))
            ->get()
            ->keyBy('date');

        return DateSeries::daily(self::TREND_DAYS, $rows, static fn (mixed $row, string $date): array => [
            'date' => $date,
            'calls' => (int) ($row->calls ?? 0),
            'tokens' => (int) ($row->tokens ?? 0),
            'cost_usd' => round((float) ($row->cost ?? 0), 4),
        ]);
    }

    /**
     * Grouped aggregate ordered by cost.
     *
     * @return Collection<int, object>
     */
    private function grouped(ReportPeriod $period, RepositoryScope $scope, string $column): Collection
    {
        // toBase(): these are aggregates, not records. Hydrating them as models would
        // run the enum casts over a grouped row and break on the string comparison.
        return $this->scoped($period, $scope)
            ->toBase()
            ->select([
                $column,
                DB::raw('COUNT(*) as calls'),
                DB::raw('COALESCE(SUM(total_tokens), 0) as tokens'),
                DB::raw('COALESCE(SUM(cost_usd), 0) as cost'),
            ])
            ->groupBy($column)
            ->orderByDesc('cost')
            ->get();
    }

    /**
     * @return Builder<AiUsageRecord>
     */
    private function scoped(ReportPeriod $period, RepositoryScope $scope): Builder
    {
        return $this->baseQuery($scope)->since($period->startsAt());
    }

    /**
     * Records the viewer is allowed to see.
     *
     * Rows with no repository — assistant chats, connection tests — are installation
     * level and only shown to a viewer whose scope is unrestricted.
     *
     * @return Builder<AiUsageRecord>
     */
    private function baseQuery(RepositoryScope $scope): Builder
    {
        $query = AiUsageRecord::query();

        if ($scope->isRestricted()) {
            $query->whereIn('git_repository_id', $scope->ids() ?? []);
        }

        return $query;
    }

    /**
     * Share of input tokens served from cache.
     */
    private function cacheHitRate(?object $row): ?float
    {
        $cached = (int) ($row->cache_read_tokens ?? 0);
        $fresh = (int) ($row->prompt_tokens ?? 0);
        $input = $cached + $fresh;

        return $input > 0 ? round(($cached / $input) * 100, 1) : null;
    }
}
