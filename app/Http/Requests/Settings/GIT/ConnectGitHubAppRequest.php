<?php

namespace App\Http\Requests\Settings\GIT;

use App\Enums\Users\UserPermission;
use Illuminate\Foundation\Http\FormRequest;

class ConnectGitHubAppRequest extends FormRequest
{
    /**
     * Whether the current user may perform this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ManageIntegrations) ?? false;
    }

    /**
     * Validation rules for this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'app_id' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'private_key' => ['required', 'string'],
        ];
    }
}
