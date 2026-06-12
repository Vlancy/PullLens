<?php

namespace App\Http\Requests\Settings\AI;

use App\Enums\AI\AiProviderDriver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiProviderRequest extends FormRequest
{
    /**
     * Determine whether the authenticated user may create AI providers.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validate AI provider settings and write-only credentials.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_driver' => ['required', 'string', Rule::in(AiProviderDriver::values())],
            'name' => ['required', 'string', 'max:255', Rule::unique('ai_providers', 'name')],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'default_model' => ['nullable', 'string', 'max:255'],
            'is_default' => ['required', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'api_key' => trim((string) $this->input('api_key', '')) ?: null,
            'base_url' => trim((string) $this->input('base_url', '')) ?: null,
            'default_model' => trim((string) $this->input('default_model', '')) ?: null,
        ]);
    }
}
