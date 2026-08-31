<?php

namespace App\Http\Requests\Findings;

use App\Enums\GIT\FindingCategory;
use App\Enums\GIT\FindingSeverity;
use App\Enums\GIT\FindingSortOption;
use App\Enums\GIT\FindingStatusFilter;
use App\Enums\Users\UserPermission;
use App\Models\GIT\GitRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and normalizes the findings list filters.
 *
 * Every filter that reaches a query is whitelisted against an enum or an existence
 * rule, so a hand-edited URL cannot select an unknown column, an unknown sort, or a
 * repository the schema does not contain.
 */
class IndexFindingsRequest extends FormRequest
{
    public const PER_PAGE = 25;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ViewFindings) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'repository_id' => ['nullable', 'uuid', Rule::exists(GitRepository::class, 'id')],
            // Comma-separated list, split in severities(); each element is checked there.
            'severity' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::in(FindingCategory::values())],
            'status' => ['nullable', 'string', Rule::in(FindingStatusFilter::values())],
            'search' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'string', Rule::in(FindingSortOption::values())],
            'author_login' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function repositoryId(): ?string
    {
        return $this->filledString('repository_id');
    }

    /**
     * Severities to include, as validated enum cases.
     *
     * Unknown values are dropped rather than rejected: the filter is a multi-select in
     * the UI and a stale bookmark should degrade, not error.
     *
     * @return array<int, string>
     */
    public function severities(): array
    {
        $raw = $this->filledString('severity');

        if ($raw === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn (string $value): bool => FindingSeverity::tryFrom($value) !== null,
        ));
    }

    public function category(): ?string
    {
        return $this->filledString('category');
    }

    public function status(): FindingStatusFilter
    {
        return FindingStatusFilter::tryFrom((string) $this->validated('status'))
            ?? FindingStatusFilter::Open;
    }

    public function search(): ?string
    {
        return $this->filledString('search');
    }

    public function sort(): FindingSortOption
    {
        return FindingSortOption::tryFrom((string) $this->validated('sort_by'))
            ?? FindingSortOption::Severity;
    }

    public function authorLogin(): ?string
    {
        return $this->filledString('author_login');
    }

    public function page(): int
    {
        return max(1, (int) ($this->validated('page') ?? 1));
    }

    /**
     * The filter state echoed back to the page so the controls stay in sync with the URL.
     *
     * @return array<string, mixed>
     */
    public function filterState(): array
    {
        return [
            'repository_id' => $this->repositoryId() ?? '',
            'severity' => implode(',', $this->severities()),
            'category' => $this->category() ?? '',
            'status' => $this->status()->value,
            'search' => $this->search() ?? '',
            'sort_by' => $this->sort()->value,
            'author_login' => $this->authorLogin() ?? '',
        ];
    }

    /**
     * A validated string input, or null when it was absent or blank.
     */
    private function filledString(string $key): ?string
    {
        $value = $this->validated($key);

        return filled($value) ? trim((string) $value) : null;
    }
}
