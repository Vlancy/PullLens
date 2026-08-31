<?php

use App\Enums\Users\UserRole;

return [

    /*
    |---------------------------------------------------------------------------
    | Bootstrap administrator
    |---------------------------------------------------------------------------
    |
    | Public self-service registration is disabled, so the very first account is
    | created by the database seeder from these values. ADMIN_PASSWORD is required
    | in production; in other environments the seeder generates a random password
    | and prints it once.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@pulllens.local'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Registration
    |---------------------------------------------------------------------------
    |
    | Kept as an explicit, auditable switch. Fortify's registration feature is
    | commented out in config/fortify.php, and the EnsureRegistrationIsDisabled
    | middleware refuses any request to a registration endpoint while this is
    | false — defence in depth against a package or route re-introducing one.
    |
    */

    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),

    /*
    |---------------------------------------------------------------------------
    | Webhooks
    |---------------------------------------------------------------------------
    |
    | Incoming provider webhooks are rejected unless their HMAC signature can be
    | verified. Disabling this is only ever acceptable for local debugging and is
    | force-enabled in production regardless of the environment value.
    |
    */

    'webhooks' => [
        'require_signature' => (bool) env('WEBHOOK_REQUIRE_SIGNATURE', true),
    ],

    /*
    |---------------------------------------------------------------------------
    | Task tracking
    |---------------------------------------------------------------------------
    |
    | Tasks are extracted from pull request reviews. PullLens does not integrate
    | with an issue tracker yet, but it detects the issue keys developers already
    | put in branch names and PR titles, and stores them against each task so a
    | later integration has something to join on.
    |
    | `tracker` says which tracker those keys belong to — Jira and Linear share the
    | PROJ-123 shape, so the format alone cannot tell them apart. `tracker_base_url`
    | turns a key into a browsable link; leave it empty and no link is rendered.
    |
    */

    'tasks' => [
        'tracker' => env('TASK_TRACKER', 'jira'),
        'tracker_base_url' => env('TASK_TRACKER_BASE_URL'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Link previews and icons
    |---------------------------------------------------------------------------
    |
    | Used by resources/views/partials/head.blade.php for the browser tab icon and
    | for the Open Graph card that WhatsApp, Slack and LinkedIn render when someone
    | shares a link. `image` is resolved through asset(), so APP_URL (or ASSET_URL)
    | must be the public URL — scrapers cannot fetch a relative path or localhost.
    |
    */

    'meta' => [
        'title' => env('META_TITLE', 'PullLens — AI Code Review That Ships High-Quality Code'),
        'description' => env('META_DESCRIPTION', 'Self-hosted AI code reviewer that puts a tireless, senior-grade reviewer on every pull request — catching security, quality, and risk before merge. Source-available. Your infrastructure. Your control.'),
        'image' => env('META_IMAGE', 'og-image.png'),
        'image_alt' => env('META_IMAGE_ALT', 'PullLens — AI code review for every pull request'),
        'theme_color' => env('META_THEME_COLOR', '#111113'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Observability
    |---------------------------------------------------------------------------
    |
    | Horizon and Telescope expose queue payloads, request bodies and credentials.
    | Access is limited to this role.
    |
    */

    'observability' => [
        'role' => UserRole::Admin->value,
    ],

];
