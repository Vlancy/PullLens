<?php

namespace App\Support\Queries\GIT;

use App\Enums\GIT\FindingSource;
use App\Enums\GIT\SecurityFindingKind;
use App\Models\GIT\PullRequestReviewFinding;

/**
 * The findings query narrowed to what the security scanners produced, for the Security page.
 *
 * Scope, severity, status, sorting and paging are FindingQuery's; this adds the tab
 * filter and widens the search to the file and the vulnerable package.
 */
class SecurityFindingQuery extends FindingQuery
{
    /**
     * Start from every finding a security scanner produced.
     */
    public function __construct()
    {
        parent::__construct(
            PullRequestReviewFinding::query()->whereIn('source', FindingSource::scanners()),
        );
    }

    /**
     * Restrict to one tab.
     */
    public function ofKind(SecurityFindingKind $kind): self
    {
        $this->builder()->where('metadata->kind', $kind->value);

        return $this;
    }

    /**
     * The title (which names the package for a vulnerability), the file and the package.
     *
     * @return array<int, string>
     */
    protected function searchColumns(): array
    {
        return ['title', 'file', 'metadata->package'];
    }
}
