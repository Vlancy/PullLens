<?php

namespace App\Http\Requests\Reports;

use App\Enums\Users\UserPermission;
use App\Models\GIT\GitRepository;
use App\Support\Reports\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the filters shared by every report page.
 *
 * Centralising them means an unrecognised period or a non-existent repository id is
 * rejected before it can reach a query, and each page only has to declare which
 * default period it wants.
 */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ViewReports) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'string', Rule::in(ReportPeriod::values())],
            // Author logins come from the provider; bound as a parameter, but still
            // length-limited so a huge value cannot be pushed into a query.
            'author' => ['nullable', 'string', 'max:255'],
            'repo_id' => ['nullable', 'uuid', Rule::exists(GitRepository::class, 'id')],
        ];
    }

    /**
     * The requested window, or the page's default when none was given.
     */
    public function period(ReportPeriod $default = ReportPeriod::AllTime): ReportPeriod
    {
        return ReportPeriod::fromRequest($this->validated('period'), $default);
    }

    /**
     * The author login to scope the report to, if any.
     */
    public function author(): ?string
    {
        $author = $this->validated('author');

        return filled($author) ? (string) $author : null;
    }

    /**
     * The repository to scope the report to, if any.
     */
    public function repositoryId(): ?string
    {
        $repositoryId = $this->validated('repo_id');

        return filled($repositoryId) ? (string) $repositoryId : null;
    }
}
