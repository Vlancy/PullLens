<?php

namespace App\Http\Requests\Settings\GIT;

use App\Enums\Users\UserPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGitRepositoriesRequest extends FormRequest
{
    /**
     * Only authenticated operators may change the tracked repository selection.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ManageIntegrations) ?? false;
    }

    /**
     * Validate the selecting account and the chosen repositories.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'string', 'uuid', Rule::exists('git_accounts', 'id')],
            'repositories' => ['present', 'array'],
            'repositories.*.installation_id' => ['required', 'integer'],
            'repositories.*.provider_repo_id' => ['required', 'integer'],
        ];
    }
}
