<?php

namespace App\Http\Requests\Settings\GIT;

use App\Enums\GIT\RepositoryTagKind;
use App\Enums\Users\UserPermission;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreGitRepositoryTagRequest extends FormRequest
{
    /**
     * Only users who may manage repositories can edit profile tags.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ManageRepositories) ?? false;
    }

    /**
     * Validate the tag name and kind.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:50',
                'regex:/^[\pL\pN .+#-]+$/u',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Str::slug((string) $value) === '') {
                        $fail('The tag name must contain at least one letter or number.');
                    }
                },
            ],
            'kind' => ['required', Rule::in(RepositoryTagKind::values())],
        ];
    }
}
