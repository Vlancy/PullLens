<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RepositoryServiceProvider;
use Vlancy\LaravelApiResponse\Providers\APIResponseProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    RepositoryServiceProvider::class,
    APIResponseProvider::class,
];
