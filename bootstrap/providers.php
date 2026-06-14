<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RepositoryServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Vlancy\LaravelApiResponse\Providers\APIResponseProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    RepositoryServiceProvider::class,
    ...(class_exists(TelescopeApplicationServiceProvider::class) ? [TelescopeServiceProvider::class] : []),
    APIResponseProvider::class,
];
