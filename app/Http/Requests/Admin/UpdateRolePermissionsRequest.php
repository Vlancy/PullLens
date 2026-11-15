<?php

namespace App\Http\Requests\Admin;

use App\Enums\Users\UserPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Validates a change to one role's permission set (and, for custom roles, its label).
 */
class UpdateRolePermissionsRequest extends FormRequest
{
    /**
     * Whether the current user may perform this request.
     */
    public function authorize(): bool
    {
        // Editing the matrix is strictly an administrator action: it can grant any
        // permission in the system, so it must never be delegated by permission alone.
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Validation rules for this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Present means "granted"; an unticked box is simply absent from the array.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(UserPermission::values())],
            // Only honoured for custom roles; built-in labels come from the enum.
            'label' => [
                'sometimes', 'nullable', 'string', 'max:50',
                Rule::unique(config('permission.table_names.roles', 'roles'), 'label')->ignore($this->role(), 'name'),
            ],
        ];
    }

    /**
     * Trim the label before the rules run.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('label'))) {
            $this->merge(['label' => trim($this->input('label'))]);
        }
    }

    /**
     * The name of the role being edited, taken from the route. 404s for unknown roles.
     */
    public function role(): string
    {
        $name = (string) $this->route('role');

        abort_unless(Role::query()->where('name', $name)->exists(), 404);

        return $name;
    }

    /**
     * The new label, or null to leave it unchanged.
     */
    public function label(): ?string
    {
        $label = $this->validated('label');

        return filled($label) ? (string) $label : null;
    }

    /**
     * The permissions this role should hold.
     *
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return array_values(array_unique((array) $this->validated('permissions', [])));
    }

    /**
     * Custom validation messages for this request.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permissions.*.in' => 'One of the selected permissions does not exist.',
            'label.unique' => 'Another role already uses this name.',
        ];
    }
}
