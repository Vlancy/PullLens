<?php

namespace App\Http\Requests\Tasks;

use App\Enums\GIT\TaskStatus;
use App\Enums\GIT\TaskType;
use App\Enums\Users\UserPermission;
use App\Models\GIT\GitRepository;
use App\Models\GIT\Project;
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

    /** Feature rows per page in the features view. */
    public const FEATURES_PER_PAGE = 25;

    /** The board's two layouts: grouped by feature, or every task in one list. */
    public const VIEW_FEATURES = 'features';

    public const VIEW_LIST = 'list';

    /** The `feature` filter value that selects tasks with no feature. */
    public const UNGROUPED = 'ungrouped';

    /** The `project` filter value that selects repositories without a project. */
    public const NO_PROJECT = 'none';

    /**
     * Whether the current user may perform this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ViewTasks) ?? false;
    }

    /**
     * Normalise the "came back only" flag before validation.
     *
     * The board's router serialises a boolean as the strings "true" / "false", which
     * the boolean rule rejects; without this the toggle silently bounced back.
     */
    protected function prepareForValidation(): void
    {
        $rework = $this->query('rework');

        if (is_string($rework) && in_array(strtolower($rework), ['true', 'false'], true)) {
            $this->merge(['rework' => strtolower($rework) === 'true' ? '1' : '0']);
        }
    }

    /**
     * Validation rules for this request.
     *
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
            // A project id, or the sentinel for repositories that belong to none.
            'project' => $this->input('project') === self::NO_PROJECT
                ? ['nullable', 'string']
                : ['nullable', 'uuid', Rule::exists(Project::class, 'id')],
            'period' => ['nullable', 'string', Rule::in(ReportPeriod::values())],
            'rework' => ['nullable', 'boolean'],
            'related_to' => ['nullable', 'uuid', Rule::exists(PullRequestTask::class, 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
            'view' => ['nullable', 'string', Rule::in([self::VIEW_FEATURES, self::VIEW_LIST])],
            // A feature id, or the sentinel for tasks that have none.
            'feature' => ['nullable', 'string', 'regex:/^('.self::UNGROUPED.'|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/'],
            'features_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The free-text search term, or null when the box is empty.
     */
    public function keyword(): ?string
    {
        return $this->filled('search') ? trim((string) $this->validated('search')) : null;
    }

    /**
     * The task type to narrow to, if any.
     */
    public function type(): ?TaskType
    {
        return TaskType::tryFrom((string) $this->validated('type'));
    }

    /**
     * The lifecycle state to narrow to, if any.
     */
    public function status(): ?TaskStatus
    {
        return TaskStatus::tryFrom((string) $this->validated('status'));
    }

    /**
     * The developer to narrow to, if any.
     */
    public function author(): ?string
    {
        return $this->filled('author') ? (string) $this->validated('author') : null;
    }

    /**
     * The repository to narrow to, if any.
     */
    public function repositoryId(): ?string
    {
        return $this->filled('repo_id') ? (string) $this->validated('repo_id') : null;
    }

    /**
     * The project to narrow to - an id or `none` - if any.
     */
    public function project(): ?string
    {
        return $this->filled('project') ? strtolower((string) $this->validated('project')) : null;
    }

    /**
     * The board defaults to all time - it is a backlog view, not a period report.
     */
    public function period(): ReportPeriod
    {
        return ReportPeriod::fromRequest($this->validated('period'), ReportPeriod::AllTime);
    }

    /**
     * Whether to show only work that came back - something fixed or reverted it.
     */
    public function onlyRework(): bool
    {
        return $this->boolean('rework');
    }

    /**
     * The task whose linked work should be shown, if the board was pivoted to one.
     */
    public function relatedTo(): ?string
    {
        return $this->filled('related_to') ? (string) $this->validated('related_to') : null;
    }

    /**
     * The board layout: features (the default) or the flat list.
     */
    public function view(): string
    {
        return $this->filled('view') ? (string) $this->validated('view') : self::VIEW_FEATURES;
    }

    /**
     * Whether the board is grouped by feature.
     */
    public function wantsFeatures(): bool
    {
        return $this->view() === self::VIEW_FEATURES;
    }

    /**
     * The feature whose tasks to list - an id or `ungrouped` - if any.
     */
    public function feature(): ?string
    {
        return $this->filled('feature') ? strtolower((string) $this->validated('feature')) : null;
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
            'project' => $this->project() ?? '',
            'period' => $this->period()->value,
            'rework' => $this->onlyRework(),
            'related_to' => $this->relatedTo() ?? '',
            'view' => $this->view(),
            'feature' => $this->feature() ?? '',
        ];
    }
}
