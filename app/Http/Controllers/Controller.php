<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Vlancy\LaravelApiResponse\Traits\APIResponseTrait;

/**
 * Base controller for the application.
 *
 * Provides the shared JSON response helpers and `$this->authorize()` so policies
 * can be invoked from actions that have no form request to carry the check.
 */
abstract class Controller
{
    use APIResponseTrait, AuthorizesRequests;
}
