<?php

namespace App\Http\Requests\Settings\AI;

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

        return $rules;
    }
}
