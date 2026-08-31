<?php

namespace App\Models\AI;

use App\Enums\AI\AiProviderDriver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider_driver',
    'name',
    'credentials',
    'base_url',
    'default_model',
    'is_default',
    'is_enabled',
])]
#[Hidden(['credentials'])]
class AiProvider extends Model
{
    use HasUuids;

    /**
     * Return casts for encrypted AI provider configuration.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_driver' => AiProviderDriver::class,
            'credentials' => 'encrypted:array',
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
