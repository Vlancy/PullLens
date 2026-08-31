<?php

use App\Repositories\Contracts\AI\AiProviderRepositoryInterface;
use App\Repositories\Contracts\GIT\GitAccountRepositoryInterface;
use App\Repositories\Contracts\GIT\GitProviderAppRepositoryInterface;
use App\Repositories\Contracts\GIT\GitRepositoryRepositoryInterface;
use App\Repositories\Contracts\Users\UserRepositoryInterface;
use App\Repositories\Eloquent\AI\AiProviderRepository;
use App\Repositories\Eloquent\GIT\GitAccountRepository;
use App\Repositories\Eloquent\GIT\GitProviderAppRepository;
use App\Repositories\Eloquent\GIT\GitRepositoryRepository;
use App\Repositories\Eloquent\Users\UserRepository;

return [
    'driver' => env('DB_REPOSITORY_DRIVER', 'eloquent'),

    'drivers' => [
        'eloquent' => [
            AiProviderRepositoryInterface::class => AiProviderRepository::class,
            GitAccountRepositoryInterface::class => GitAccountRepository::class,
            GitProviderAppRepositoryInterface::class => GitProviderAppRepository::class,
            GitRepositoryRepositoryInterface::class => GitRepositoryRepository::class,
            UserRepositoryInterface::class => UserRepository::class,
        ],
    ],
];
