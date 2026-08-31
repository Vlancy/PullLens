<?php

namespace App\Http\Requests\Tasks;

use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskType;
use App\Enums\Users\UserPermission;
use App\Models\GIT\GitRepository;
use App\Models\GIT\PullRequestTask;
use App\Support\Reports\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the filters on the tasks board.
 *
 * Every value that reaches a query is whitelisted against an enum or an existence
 * rule, so a hand-edited URL cannot select an unknown column or an unknown status.
 */
class IndexTasksRequest extends FormRequest
{
    public const PER_PAGE = 25;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ViewTasks) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::in(TaskType::values())],
            'status' => ['nullable', 'string', Rule::in(TaskStatus::values())],
            'author' => ['nullable', 'string', 'max:255'],
            'repo_id' => ['nullable', 'uuid', Rule::exists(GitRepository::class, 'id')],
            'period' => ['nullable', 'string', Rule::in(ReportPeriod::values())],
            'rework' => ['nullable', 'boolean'],
            'related_to' => ['nullable', 'uuid', Rule::exists(PullRequestTask::class, 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function keyword(): ?string
    {
        return $this->filled('search') ? trim((string) $this->validated('search')) : null;
    }

    public function type(): ?TaskType
    {
        return TaskType::tryFrom((string) $this->validated('type'));
    }

    public function status(): ?TaskStatus
    {
        return TaskStatus::tryFrom((string) $this->validated('status'));
    }

    public function author(): ?string
    {
        return $this->filled('author') ? (string) $this->validated('author') : null;
    }

    public function repositoryId(): ?string
    {
        return $this->filled('repo_id') ? (string) $this->validated('repo_id') : null;
    }

    /**
     * The board defaults to all time — it is a backlog view, not a period report.
     */
    public function period(): ReportPeriod
    {
        return ReportPeriod::fromRequest($this->validated('period'), ReportPeriod::AllTime);
    }

    public function onlyRework(): bool
    {
        return $this->boolean('rework');
    }

    public function relatedTo(): ?string
    {
        return $this->filled('related_to') ? (string) $this->validated('related_to') : null;
    }

    /**
     * The filter state echoed back so the controls stay in sync with the URL.
     *
     * @return array<string, mixed>
     */
    public function filterState(): array
    {
        return [
            'search' => $this->keyword() ?? '',
            'type' => $this->type()?->value ?? '',
            'status' => $this->status()?->value ?? '',
            'author' => $this->author() ?? '',
            'repo_id' => $this->repositoryId() ?? '',
            'period' => $this->period()->value,
            'rework' => $this->onlyRework(),
            'related_to' => $this->relatedTo() ?? '',
        ];
    }
}
