<?php

namespace App\Http\Requests\Admin;

use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a change to one role's permission set.
 */
class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Editing the matrix is strictly an administrator action: it can grant any
        // permission in the system, so it must never be delegated by permission alone.
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Present means "granted"; an unticked box is simply absent from the array.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(UserPermission::values())],
        ];
    }

    /**
     * The role being edited, taken from the route.
     */
    public function role(): UserRole
    {
        return UserRole::from((string) $this->route('role'));
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return array_values(array_unique((array) $this->validated('permissions', [])));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.in' => 'One of the selected permissions does not exist.',
        ];
    }
}
