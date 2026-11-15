<?php

namespace App\Http\Requests\Settings\Projects;

use App\Enums\Users\UserPermission;
use App\Support\Tasks\FeatureScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeatureGroupingRequest extends FormRequest
{
    /**
     * Only users who can manage repositories may group tasks.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ManageRepositories) ?? false;
    }

    /**
     * Validate the scope: a project, or a repository that has no project.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required_without:repository_id', 'prohibits:repository_id', 'nullable', 'uuid', 'exists:projects,id'],
            'repository_id' => [
                'required_without:project_id',
                'nullable',
                'uuid',
                Rule::exists('git_repositories', 'id')->whereNull('project_id'),
            ],
        ];
    }

    /**
     * Custom messages for the scope rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'repository_id.exists' => 'That repository belongs to a project; group the project instead.',
        ];
    }

    /**
     * The feature scope the request names.
     */
    public function scope(): FeatureScope
    {
        $projectId = $this->validated('project_id');

        return $projectId !== null
            ? new FeatureScope($projectId, null)
            : new FeatureScope(null, $this->validated('repository_id'));
    }
}
