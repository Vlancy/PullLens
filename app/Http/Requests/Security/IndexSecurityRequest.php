<?php

namespace App\Http\Requests\Security;

use App\Enums\GIT\SecurityFindingKind;
use App\Http\Requests\Findings\IndexFindingsRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Validates and normalizes the Security page filters.
 *
 * The shared filters (repository, severity, status, search, sort, page) and the
 * permission check come from IndexFindingsRequest; this adds the tab and drops the
 * filters that only mean something for AI review findings.
 */
class IndexSecurityRequest extends IndexFindingsRequest
{
    /** Review-list filters that do not apply to scanner findings. */
    private const REVIEW_ONLY_FILTERS = ['category', 'source', 'author_login'];

    /**
     * Validation rules for this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Arr::except(parent::rules(), self::REVIEW_ONLY_FILTERS),
            'kind' => ['nullable', 'string', Rule::in(SecurityFindingKind::values())],
        ];
    }

    /**
     * The tab being shown, defaulting to secrets.
     */
    public function kind(): SecurityFindingKind
    {
        return SecurityFindingKind::tryFrom((string) $this->validated('kind')) ?? SecurityFindingKind::Secret;
    }

    /**
     * The filter state echoed back to the page so the controls stay in sync with the URL.
     *
     * @return array<string, mixed>
     */
    public function filterState(): array
    {
        return [
            ...Arr::except(parent::filterState(), self::REVIEW_ONLY_FILTERS),
            'kind' => $this->kind()->value,
        ];
    }
}
