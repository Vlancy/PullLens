<?php

namespace App\Http\Requests\Admin;

use App\Models\Users\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the query parameters accepted by the user listing.
 *
 * Bounding `per_page` here stops a crafted URL from asking the database for the
 * entire users table in one response.
 */
class IndexUserRequest extends FormRequest
{
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Whether the current user may perform this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    /**
     * Validation rules for this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The trimmed keyword to filter the listing by.
     */
    public function keyword(): string
    {
        return trim((string) $this->validated('search', ''));
    }

    /**
     * The requested page size, falling back to the default.
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
