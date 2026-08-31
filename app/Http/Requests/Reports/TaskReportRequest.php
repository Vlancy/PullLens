<?php

namespace App\Http\Requests\Reports;

use App\Enums\GIT\TaskType;
use Illuminate\Validation\Rule;

/**
 * Filters for the delivered-tasks report.
 *
 * Extends the shared report filters with a task type, so the same period, author and
 * repository validation is not restated here.
 */
class TaskReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'type' => ['nullable', 'string', Rule::in(TaskType::values())],
        ];
    }

    /**
     * The task type to narrow to, if any.
     */
    public function taskType(): ?TaskType
    {
        return TaskType::tryFrom((string) $this->validated('type'));
    }
}
