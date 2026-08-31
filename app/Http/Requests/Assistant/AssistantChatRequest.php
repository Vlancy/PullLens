<?php

namespace App\Http\Requests\Assistant;

use App\Enums\Users\UserPermission;
use App\Models\AI\AiProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a turn in the assistant conversation.
 *
 * Note that the browser's copy of the history is deliberately *not* accepted as
 * input: the server reads it from AssistantConversationStore instead, so a crafted
 * request cannot fabricate prior assistant turns to steer the model.
 */
class AssistantChatRequest extends FormRequest
{
    /** Upper bound on a single message, to cap prompt cost. */
    private const MAX_MESSAGE_LENGTH = 2000;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(UserPermission::UseAssistant) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.self::MAX_MESSAGE_LENGTH],
            'provider_id' => ['nullable', 'uuid', Rule::exists(AiProvider::class, 'id')],
        ];
    }

    public function message(): string
    {
        return (string) $this->validated('message');
    }

    public function providerId(): ?string
    {
        $providerId = $this->validated('provider_id');

        return filled($providerId) ? (string) $providerId : null;
    }
}
