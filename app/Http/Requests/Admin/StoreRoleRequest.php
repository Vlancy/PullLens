<?php

namespace App\Http\Requests\Admin;

use App\Enums\Users\UserPermission;
use App\Enums\Users\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates the creation of a custom role.
 */
class StoreRoleRequest extends FormRequest
{
    /**
     * Whether the current user may perform this request.
     */
    public function authorize(): bool
    {
        // A new role can be granted any permission, so this stays administrator-only.
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Validation rules for this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $builtInLabels = array_map(
            static fn (UserRole $role): string => mb_strtolower($role->label()),
            UserRole::cases(),
        );

        return [
            'label' => [
                'required', 'string', 'max:50',
                Rule::unique(config('permission.table_names.roles', 'roles'), 'label'),
                // Must not shadow a built-in role by name or slug.
                function (string $attribute, mixed $value, \Closure $fail) use ($builtInLabels): void {
                    $value = (string) $value;

                    if (in_array(mb_strtolower($value), $builtInLabels, true)
                        || UserRole::tryFrom(Str::slug($value)) !== null) {
                        $fail('This name is reserved for a built-in role.');
                    }
                },
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(UserPermission::values())],
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

        if (! $this->has('permissions')) {
            $this->merge(['permissions' => []]);
        }
    }

    /**
     * The label for the new role.
     */
    public function label(): string
    {
        return (string) $this->validated('label');
    }

    /**
     * The permissions to grant the new role.
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
