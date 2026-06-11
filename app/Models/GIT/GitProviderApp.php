<?php

namespace App\Models\GIT;

use App\Enums\GIT\GitProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'name',
    'app_id',
    'client_id',
    'client_secret',
    'webhook_secret',
    'private_key',
    'slug',
    'configured_at',
])]
#[Hidden(['client_secret', 'webhook_secret', 'private_key'])]
class GitProviderApp extends Model
{
    /**
     * Return casts for encrypted Git provider application secrets.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'client_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'private_key' => 'encrypted',
            'configured_at' => 'datetime',
        ];
    }
}
