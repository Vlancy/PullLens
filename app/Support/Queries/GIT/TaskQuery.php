<?php

namespace App\Support\Queries\GIT;

use App\Enums\GIT\TaskRelation;
use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskType;
use App\Models\GIT\PullRequestTask;
use App\Support\Access\RepositoryScope;
use App\Support\Reports\ReportPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fluent, whitelisted query builder for the tasks board.
 *
 * Every filter takes an already-validated value and binds it as a parameter, so no
 * caller-supplied string reaches SQL. Keeping the filters here rather than in the
 * controller means the board and any future export narrow identically.
 */
class TaskQuery
{
    /** @var Builder<PullRequestTask> */
    private Builder $query;

    /**
     * Create the instance.
     */
    public function __construct(?Builder $query = null)
    {
        $this->query = $query ?? PullRequestTask::query();
    }

    /**
     * Restrict to the repositories the viewer may see.
     */
    public function withinScope(RepositoryScope $scope): self
    {
        $scope->applyTo($this->query);

        return $this;
    }

    /**
     * Free-text search across title, description and tracker key.
     */
    public function matching(?string $keyword): self
    {
        $this->query->search($keyword);

        return $this;
    }

    /**
     * Restrict to one kind of work, such as features or bug fixes.
     */
    public function ofType(?TaskType $type): self
    {
        if ($type !== null) {
            $this->query->where('type', $type->value);
        }

        return $this;
    }

    /**
     * Restrict to one lifecycle state, such as delivered or reworked.
     */
    public function withStatus(?TaskStatus $status): self
    {
        if ($status !== null) {
            $this->query->where('status', $status->value);
        }

        return $this;
    }

    /**
     * Restrict to the work of one developer.
     */
    public function authoredBy(?string $login): self
    {
        if (filled($login)) {
            $this->query->where('author_login', $login);
        }

        return $this;
    }

    /**
     * Restrict to the window a period describes.
     *
     * Filters on delivery date, falling back to creation date for work that has not
     * shipped - otherwise in-progress tasks would vanish from every bounded period.
     */
    public function inPeriod(ReportPeriod $period): self
    {
        $from = $period->startsAt();
        $to = $period->endsAt();

        if ($from === null && $to === null) {
            return $this;
        }

        $this->query->where(function (Builder $inner) use ($from, $to): void {
            $inner
                ->when($from, fn (Builder $q) => $q->where(
                    fn (Builder $w) => $w->where('delivered_at', '>=', $from)
                        ->orWhere(fn (Builder $u) => $u->whereNull('delivered_at')->where('created_at', '>=', $from)),
                ))
                ->when($to, fn (Builder $q) => $q->where(
                    fn (Builder $w) => $w->where('delivered_at', '<=', $to)
                        ->orWhere(fn (Builder $u) => $u->whereNull('delivered_at')->where('created_at', '<=', $to)),
                ));
        });

        return $this;
    }

    /**
     * Show only work that came back - something fixed or reverted it.
     */
    public function onlyNeedingRework(bool $enabled): self
    {
        if ($enabled) {
            $this->query->neededRework();
        }

        return $this;
    }

    /**
     * Show only the tasks linked to a specific other task.
     */
    public function relatedTo(?string $taskId): self
    {
        if (filled($taskId)) {
            $this->query->where(function (Builder $inner) use ($taskId): void {
                $inner
                    ->whereHas('links', fn (Builder $q) => $q->where('related_task_id', $taskId))
                    ->orWhereHas('inboundLinks', fn (Builder $q) => $q->where('task_id', $taskId));
            });
        }

        return $this;
    }

    /**
     * Paginate, newest delivery first, with the relations the board renders.
     *
     * @return LengthAwarePaginator<int, PullRequestTask>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query
            ->with([
                'pullRequest:id,number,title,web_url,state',
                'repository:id,name,full_name',
                'links.relatedTask:id,title,type,status,dedupe_key',
                'inboundLinks.task:id,title,type,status,dedupe_key',
            ])
            // Undelivered work sorts by when it was seen, so nothing lands undated.
            ->orderByRaw('COALESCE(delivered_at, created_at) DESC')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Counts for the board's summary tiles, under the same filters.
     *
     * @return array<string, int|float>
     */
    public function summary(): array
    {
        $base = (clone $this->query);

        $total = (clone $base)->count();
        $firstTimeRight = (clone $base)->firstTimeRight()->count();
        $reworked = (clone $base)->neededRework()->count();
        $hours = (float) (clone $base)->sum('estimated_hours');

        return [
            'total' => $total,
            'first_time_right' => $firstTimeRight,
            'reworked' => $reworked,
            // Share of delivered work that never came back. Null when nothing has
            // shipped yet, so the UI can say "no data" instead of a misleading 0%.
            'first_time_right_rate' => $total > 0 ? round(($firstTimeRight / $total) * 100, 1) : null,
            'estimated_hours' => round($hours, 1),
        ];
    }

    /**
     * Relation options offered by the board, for the filter legend.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function relationOptions(): array
    {
        return TaskRelation::options();
    }

    /**
     * The underlying query, for callers that need to compose further.
     *
     * @return Builder<PullRequestTask>
     */
    public function builder(): Builder
    {
        return $this->query;
    }
}
