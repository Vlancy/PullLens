<?php

namespace App\Http\Requests\Settings\Projects;

use App\Enums\Users\UserPermission;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    /**
     * Only users who can manage repositories may create projects.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ManageRepositories) ?? false;
    }

    /**
     * Validate the new project's name and description.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:projects,name'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
