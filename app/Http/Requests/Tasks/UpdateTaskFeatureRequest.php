<?php

namespace App\Http\Requests\Tasks;

use App\Enums\Users\UserPermission;
use App\Models\GIT\TaskFeature;
use App\Support\Access\RepositoryScope;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskFeatureRequest extends FormRequest
{
    /**
     * Repository managers may correct features they can see; others get a 404.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! ($user?->hasPermission(UserPermission::ManageRepositories) ?? false)) {
            return false;
        }

        abort_unless(RepositoryScope::forUser($user)->allowsAny($this->feature()->repositoryIds()), 404);

        return true;
    }

    /**
     * Validate the new name and description.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The feature named in the route.
     */
    public function feature(): TaskFeature
    {
        /** @var TaskFeature */
        return $this->route('taskFeature');
    }
}
