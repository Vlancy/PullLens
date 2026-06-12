<?php

namespace App\Http\Requests\Settings\AI;

use App\Enums\AI\AiProviderDriver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestAiProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'api_key' => trim((string) $this->input('api_key', '')) ?: null,
            'base_url' => trim((string) $this->input('base_url', '')) ?: null,
            'default_model' => trim((string) $this->input('default_model', '')) ?: null,
        ]);
    }
}
