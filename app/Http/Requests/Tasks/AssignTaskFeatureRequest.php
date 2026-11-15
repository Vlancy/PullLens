<?php

namespace App\Http\Requests\Tasks;

use App\Enums\Users\UserPermission;
use App\Models\GIT\PullRequestTask;
use App\Support\Access\RepositoryScope;
use Illuminate\Foundation\Http\FormRequest;

class AssignTaskFeatureRequest extends FormRequest
{
    /**
     * Repository managers may move tasks they can see; others get a 404.
     *
     * @return bool Whether the user may manage repositories.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! ($user?->hasPermission(UserPermission::ManageRepositories) ?? false)) {
            return false;
        }

        abort_unless(RepositoryScope::forUser($user)->allows($this->task()->git_repository_id), 404);

        return true;
    }

    /**
     * Validate an existing feature id or a name for a new feature; neither means ungrouped.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'feature_id' => ['nullable', 'uuid', 'exists:task_features,id', 'prohibits:new_name'],
            'new_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * The task named in the route.
     *
     * @return PullRequestTask The bound task.
     */
    public function task(): PullRequestTask
    {
        /** @var PullRequestTask */
        return $this->route('pullRequestTask');
    }
}
