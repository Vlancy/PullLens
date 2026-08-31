<?php

namespace App\Models\GIT;

use App\Enums\GIT\GitProvider;
use Database\Factories\GitAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider',
    'provider_user_id',
    'nickname',
    'name',
    'email',
    'avatar_url',
    'access_token',
    'refresh_token',
    'scopes',
    'token_expires_at',
    'connected_at',
    'last_used_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class GitAccount extends Model
{
    /** @use HasFactory<GitAccountFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Return casts for encrypted tokens and provider/date value objects.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => GitProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'encrypted:array',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
