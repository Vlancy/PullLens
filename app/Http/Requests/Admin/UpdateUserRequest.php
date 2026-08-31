<?php

namespace App\Http\Requests\Admin;

use App\Enums\Users\RepositoryAccessLevel;
use App\Enums\Users\UserRole;
use App\Models\GIT\GitRepository;
use App\Models\Users\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates administrator-driven account edits.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            // `rfc` only — deliberately not `dns`: a DNS lookup would make account
            // creation fail whenever resolution is slow or blocked.
            'email' => [
                'required', 'string', 'lowercase', 'email:rfc', 'max:255',
                Rule::unique(User::class, 'email')->ignore($target?->getKey()),
            ],
            // Blank means "leave the existing password alone".
            'password' => ['nullable', 'string', Password::defaults()],
            'role' => ['required', 'string', Rule::in(UserRole::values())],

            // Per-repository grants, keyed by repository id. Only meaningful for roles
            // without `repositories.view-all`; ignored (and cleared) for the others.
            'repositories' => ['nullable', 'array'],
            'repositories.*' => ['string', Rule::in(RepositoryAccessLevel::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * Attributes to persist, with the password omitted when it was left blank.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        $attributes = $this->safe()->only(['name', 'email', 'password']);

        if (blank($attributes['password'] ?? null)) {
            unset($attributes['password']);
        }

        return $attributes;
    }

    public function role(): UserRole
    {
        return UserRole::from((string) $this->validated('role'));
    }

    /**
     * Repository grants to apply, as repository id => access level.
     *
     * Ids that no longer exist are dropped rather than rejected: the form is populated
     * from a live list, so a stale entry means the repository was untracked between
     * render and submit, not that the request is hostile.
     *
     * @return array<string, string>
     */
    public function repositoryGrants(): array
    {
        $submitted = (array) $this->validated('repositories', []);

        if ($submitted === []) {
            return [];
        }

        $existing = GitRepository::query()
            ->whereIn('id', array_keys($submitted))
            ->pluck('id')
            ->all();

        return array_intersect_key($submitted, array_flip($existing));
    }
}
