<?php

namespace App\Http\Controllers\Webhooks\GIT;

use App\Http\Controllers\Controller;

class GitHubWebhookController extends Controller
{
    /**
     * Reject GitHub webhooks until signature verification and processing are implemented.
     */
    public function __invoke(): never
    {
        abort(501);
    }
}
