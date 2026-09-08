<?php

namespace App\Http\Requests\Settings\Users;

use App\Concerns\PasswordValidationRules;
use App\Policies\Users\AccountSelfServicePolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a user deleting their own account.
 */
class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * The last remaining administrator may not delete themselves.
     *
     * Registration is disabled, so an installation with zero administrators cannot
     * create one again - the account has to outlive its owner's impulse to leave.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && (new AccountSelfServicePolicy)->deleteOwnAccount($user)->allowed();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }
}
