<?php

namespace App\Http\Requests\Settings\Projects;

use Illuminate\Validation\Rule;

class UpdateProjectRequest extends StoreProjectRequest
{
    /**
     * Validate project edits, letting the project keep its own name.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['required', 'string', 'max:100', Rule::unique('projects', 'name')->ignore($this->route('project'))];

        return $rules;
    }
}
