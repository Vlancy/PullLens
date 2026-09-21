<?php

namespace App\Http\Requests\Settings\AI;

use App\Models\AI\AiProvider;
use Illuminate\Validation\Rule;

class UpdateAiProviderRequest extends StoreAiProviderRequest
{
    /**
     * Validate AI provider updates while allowing credentials to remain unchanged.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = [
            'required',
            'string',
            'max:255',
            Rule::unique('ai_providers', 'name')->ignore($this->route('aiProvider')),
        ];

        // Every repository that follows the global default resolves through this
        // provider. Disabling it would leave the resolver with nothing to return
        // and break every review, so a replacement default has to be chosen first.
        $rules['is_enabled'][] = function (string $attribute, mixed $value, callable $fail): void {
            $provider = $this->route('aiProvider');

            if ($provider instanceof AiProvider && $provider->is_default && ! $this->boolean('is_enabled')) {
                $fail('Set another provider as the default before disabling this one.');
            }
        };

        return $rules;
    }
}
