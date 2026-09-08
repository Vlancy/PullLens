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
 * Validates administrator-driven account creation.
 *
 * Public registration is disabled, so this is the only request class in the
 * application that creates a User.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Whether the current user may perform this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Validation rules for this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // `rfc` only - deliberately not `dns`: a DNS lookup would make account
            // creation fail whenever resolution is slow or blocked.
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', Password::defaults()],
            // Whitelisted against the enum so a crafted payload cannot invent a role.
            'role' => ['required', 'string', Rule::in(UserRole::values())],

            // Per-repository grants, keyed by repository id. Only meaningful for roles
            // without `repositories.view-all`; ignored (and cleared) for the others.
            'repositories' => ['nullable', 'array'],
            'repositories.*' => ['string', Rule::in(RepositoryAccessLevel::values())],
        ];
    }

    /**
     * Normalize the email before validation so uniqueness and the lowercase rule agree.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * The validated attributes that map directly onto the User model.
     *
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return $this->safe()->only(['name', 'email', 'password']);
    }

    /**
     * The role to assign to the new account.
     */
    public function role(): UserRole
    {
        return UserRole::from((string) $this->validated('role'));
    }

    /**
     * Repository grants to apply, as repository id => access level.
     *
     * Ids that do not exist are dropped rather than rejected: the form is populated
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
