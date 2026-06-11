<?php

namespace App\Http\Requests\Settings\GIT;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGitRepositorySettingsRequest extends FormRequest
{
    /**
     * Only authenticated operators may change a repository's review settings.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate the per-repository review settings.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reviews_enabled' => ['required', 'boolean'],
            'auto_review_on_open' => ['required', 'boolean'],
            'auto_approve' => ['required', 'boolean'],
            'auto_apply_labels' => ['required', 'boolean'],
            'allow_comment_replies' => ['required', 'boolean'],
            'auto_merge' => ['required', 'boolean'],
            'auto_merge_method' => ['required', Rule::in(['merge', 'squash', 'rebase'])],
            'review_language' => ['required', 'string', 'max:50'],
            'base_branches' => ['present', 'array'],
            'base_branches.*' => ['string', 'max:255'],
            'tracked_branches' => ['present', 'array'],
            'tracked_branches.*' => ['string', 'max:255'],
        ];
    }
}
