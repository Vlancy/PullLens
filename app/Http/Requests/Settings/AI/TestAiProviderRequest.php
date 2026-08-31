<?php

namespace App\Http\Requests\Settings\AI;

use App\Enums\AI\AiProviderDriver;
use App\Enums\Users\UserPermission;
use App\Models\AI\AiProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a one-off connectivity test against an AI provider configuration.
 */
class TestAiProviderRequest extends FormRequest
{
    /**
     * Whether the current user may perform this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::ManageAiProviders) ?? false;
    }

    /**
     * Validation rules for this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_driver' => ['required', 'string', Rule::in(AiProviderDriver::values())],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'default_model' => ['nullable', 'string', 'max:255'],
            'provider_id' => ['nullable', 'string', 'uuid', 'exists:ai_providers,id'],
        ];
    }

    /**
     * Normalize the input before the rules run.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'api_key' => trim((string) $this->input('api_key', '')) ?: null,
            'base_url' => trim((string) $this->input('base_url', '')) ?: null,
            'default_model' => trim((string) $this->input('default_model', '')) ?: null,
        ]);
    }

    /**
     * The key to test with.
     *
     * When editing an existing provider the key field is left blank so the stored
     * secret is never round-tripped to the browser; fall back to the persisted value.
     */
    public function resolveApiKey(): ?string
    {
        $submitted = $this->validated('api_key');

        if (filled($submitted)) {
            return (string) $submitted;
        }

        $providerId = $this->validated('provider_id');

        if (blank($providerId)) {
            return null;
        }

        return AiProvider::find($providerId)?->credentials['api_key'] ?? null;
    }
}
