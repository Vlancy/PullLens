<?php

namespace App\Http\Requests\Settings\Projects;

use App\Enums\Users\UserPermission;
use Illuminate\Foundation\Http\FormRequest;

class AssignProjectRepositoriesRequest extends FormRequest
{
    /**
     * Only users who can manage repositories may change project membership.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ManageRepositories) ?? false;
    }

    /**
     * Validate the full set of repositories the project should contain.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'repository_ids' => ['present', 'array'],
            'repository_ids.*' => ['uuid', 'exists:git_repositories,id'],
        ];
    }
}
