<?php

namespace App\Http\Requests\Findings;

use App\Enums\GIT\FindingResolutionType;
use App\Enums\Users\UserPermission;
use App\Models\GIT\PullRequestReviewFinding;
use App\Support\Access\RepositoryScope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates closing many findings at once.
 *
 * The batch is capped so a single request cannot be used to issue an unbounded
 * update, and every id must exist — an unknown id is a bug or an attack, not
 * something to silently skip.
 */
class BulkResolveFindingsRequest extends FormRequest
{
    /** Maximum findings that may be resolved in one request. */
    public const MAX_BATCH = 500;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ResolveFindings) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'finding_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'finding_ids.*' => ['required', 'uuid', Rule::exists(PullRequestReviewFinding::class, 'id')],
            'resolution_type' => ['required', 'string', Rule::in(FindingResolutionType::values())],
        ];
    }

    /**
     * Reject a batch containing findings outside the user's repository scope.
     *
     * Checked as a whole-batch rule rather than per id, because the id list is the
     * obvious place to smuggle a foreign finding into an otherwise legitimate request.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $scope = RepositoryScope::forUserManagement($this->user());

                if (! $scope->isRestricted()) {
                    return;
                }

                $outOfScope = PullRequestReviewFinding::query()
                    ->whereIn('id', (array) $this->input('finding_ids', []))
                    ->whereNotIn('git_repository_id', $scope->ids() ?? [])
                    ->exists();

                if ($outOfScope) {
                    $validator->errors()->add(
                        'finding_ids',
                        'The selection includes findings from a repository you cannot manage.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<int, string>
     */
    public function findingIds(): array
    {
        return array_values(array_unique((array) $this->validated('finding_ids')));
    }

    public function resolutionType(): FindingResolutionType
    {
        return FindingResolutionType::from((string) $this->validated('resolution_type'));
    }
}
