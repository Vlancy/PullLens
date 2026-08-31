<?php

namespace App\Http\Requests\Findings;

use App\Enums\GIT\FindingResolutionType;
use App\Enums\Users\UserPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates closing a single finding with a stated reason.
 */
class ResolveFindingRequest extends FormRequest
{
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
            'resolution_type' => ['required', 'string', Rule::in(FindingResolutionType::values())],
        ];
    }

    /**
     * The reason the finding is being closed.
     */
    public function resolutionType(): FindingResolutionType
    {
        return FindingResolutionType::from((string) $this->validated('resolution_type'));
    }
}
