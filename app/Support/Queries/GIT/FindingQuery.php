<?php

namespace App\Support\Queries\GIT;

use App\Enums\GIT\FindingSortOption;
use App\Enums\GIT\FindingStatusFilter;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Access\RepositoryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fluent, whitelisted query builder for the findings list.
 *
 * Owning filtering, sorting and paging here keeps the controller free of query logic
 * and makes the same filters reusable by the statistics service, so the numbers on
 * the page always describe the rows below them.
 *
 * Every method takes already-validated input (see IndexFindingsRequest) and binds it
 * as a parameter; no caller-supplied value is ever interpolated into SQL.
 */
class FindingQuery
{
    /** @var Builder<PullRequestReviewFinding> */
    private Builder $query;

    /**
     * Create the instance.
     */
    public function __construct(?Builder $query = null)
    {
        $this->query = $query ?? PullRequestReviewFinding::query();
    }

    /**
     * Restrict to the repositories the current user is allowed to see.
     *
     * Applied before any user-chosen filter, so a hand-edited `repository_id` can only
     * ever narrow the result set, never widen it beyond the user's grants.
     */
    public function withinScope(RepositoryScope $scope): self
    {
        $scope->applyTo($this->query);

        return $this;
    }

    /**
     * Restrict to the given severities.
     *
     * @param  array<int, string>  $severities
     */
    public function withSeverities(array $severities): self
    {
        if ($severities !== []) {
            $this->query->whereIn('severity', $severities);
        }

        return $this;
    }

    /**
     * Restrict to one finding category.
     */
    public function inCategory(?string $category): self
    {
        if (filled($category)) {
            $this->query->where('category', $category);
        }

        return $this;
    }

    /**
     * Restrict to findings from one source: the AI review or the secret scan.
     */
    public function fromSource(?string $source): self
    {
        if (filled($source)) {
            $this->query->where('source', $source);
        }

        return $this;
    }

    /**
     * Restrict by resolution state.
     */
    public function withStatus(FindingStatusFilter $status): self
    {
        $status->apply($this->query);

        return $this;
    }

    /**
     * Case-insensitive search over the searchable columns.
     *
     * LIKE wildcards in the keyword are escaped so a user typing "%" searches for a
     * literal percent sign instead of matching every row. LOWER(...) LIKE rather than
     * ILIKE keeps the search portable across database engines.
     */
    public function matching(?string $keyword): self
    {
        if (blank($keyword)) {
            return $this;
        }

        $like = '%'.addcslashes(mb_strtolower($keyword), '%_\\').'%';
        $grammar = $this->query->getQuery()->getGrammar();

        $this->query->where(function (Builder $q) use ($like, $grammar): void {
            foreach ($this->searchColumns() as $column) {
                // Columns come from searchColumns(), never from input; wrap() turns a
                // "json->key" path into the engine's JSON extraction.
                $q->orWhereRaw('LOWER('.$grammar->wrap($column).") LIKE ? ESCAPE '\\'", [$like]);
            }
        });

        return $this;
    }

    /**
     * The columns the free-text search looks in.
     *
     * @return array<int, string>
     */
    protected function searchColumns(): array
    {
        return ['title'];
    }

    /**
     * Restrict to findings on pull requests opened by one author.
     */
    public function authoredBy(?string $login): self
    {
        if (filled($login)) {
            $this->query->whereHas(
                'pullRequest',
                fn (Builder $pr) => $pr->where('author_login', $login),
            );
        }

        return $this;
    }

    /**
     * Apply the requested ordering.
     */
    public function sortBy(FindingSortOption $sort): self
    {
        $sort->apply($this->query);

        return $this;
    }

    /**
     * Total rows matching the current filters, ignoring paging.
     */
    public function count(): int
    {
        return (clone $this->query)->count();
    }

    /**
     * Fetch one page of results with the relations the list renders.
     *
     * @return Collection<int, PullRequestReviewFinding>
     */
    public function page(int $page, int $perPage): Collection
    {
        // Cloned so paging does not leave a LIMIT on the shared builder, which would
        // silently corrupt a count() taken afterwards.
        return (clone $this->query)
            ->with([
                'pullRequest:id,number,title,web_url,state,git_repository_id',
                'repository:id,name,full_name',
            ])
            ->forPage($page, $perPage)
            ->get();
    }

    /**
     * The underlying builder, for callers that need to compose further.
     *
     * @return Builder<PullRequestReviewFinding>
     */
    public function builder(): Builder
    {
        return $this->query;
    }
}
