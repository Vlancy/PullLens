<?php

use App\Enums\Users\UserRole;

return [

    /*
    |---------------------------------------------------------------------------
    | Release
    |---------------------------------------------------------------------------
    |
    | Baked into the published image at build time from the git tag. Note the name:
    | PULLLENS_VERSION is the Compose variable that selects which image tag to run and
    | lives in .env, which is handed to the container through env_file - and a real
    | environment variable beats an image ENV, so reusing that name here would make
    | every instance report its tag selector instead of its actual build. A container
    | built from source reports "source".
    |
    */

    'version' => env('PULLLENS_RELEASE', 'source'),

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
    | false - defence in depth against a package or route re-introducing one.
    |
    */

    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),

    /*
    |---------------------------------------------------------------------------
    | Login as the home page
    |---------------------------------------------------------------------------
    |
    | PullLens ships with a public landing page and a public copy of the user
    | guide, which is what you want on an instance people are meant to find. An
    | internal deployment usually wants the opposite: turn this on and `/` serves
    | the login screen instead of the landing page, and `/docs` stops responding
    | altogether, so the instance gives away nothing before a visitor signs in.
    |
    | The guide is still readable from a clone at docs/guide/index.html, and on
    | GitHub, so turning this on costs nobody the documentation.
    |
    */

    'homepage_login' => (bool) env('HOMEPAGE_LOGIN', false),

    /*
    |---------------------------------------------------------------------------
    | Hide the login link on the landing page
    |---------------------------------------------------------------------------
    |
    | Removes the "Log in" button and the invite-only note from the landing page,
    | so a visitor is shown the product without being shown the way in. The login
    | route itself is unchanged and still reachable at /login by anyone who has
    | been given the address; this only stops the page advertising it.
    |
    | Irrelevant when `homepage_login` is on, because the home page is then the
    | login screen itself.
    |
    */

    'hide_login' => (bool) env('HIDE_LOGIN', false),

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
    | Review cost controls
    |---------------------------------------------------------------------------
    |
    | The diff dominates the token cost of a review. These caps bound it. The
    | defaults are deliberately lower than "as much as the model will take": past a
    | few thousand lines the reviewer's findings do not improve, but the bill keeps
    | rising linearly.
    |
    | `max_total_patch_bytes` is the ceiling for the whole PR, `max_patch_bytes_per_file`
    | stops one generated file consuming the entire budget. Roughly four bytes per
    | token, so 120 KB is about 30k input tokens.
    |
    | `task_context_limit` is how many earlier tasks are offered to the reviewer for
    | relationship detection. Each costs roughly 25 tokens of prompt.
    |
    */

    'reviews' => [
        'max_total_patch_bytes' => (int) env('REVIEW_MAX_TOTAL_PATCH_BYTES', 120_000),
        'max_patch_bytes_per_file' => (int) env('REVIEW_MAX_PATCH_BYTES_PER_FILE', 20_000),
        'task_context_limit' => (int) env('REVIEW_TASK_CONTEXT_LIMIT', 25),
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
    | `tracker` says which tracker those keys belong to - Jira and Linear share the
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
    | HTTP
    |---------------------------------------------------------------------------
    |
    | A FastCGI front end reads response headers into a fixed buffer and answers a
    | 502 when they do not fit - "upstream sent too big header" in the web server
    | log, a blank error page for the user, nothing in the application log. Signed
    | in pages send roughly 1.8 KB of headers, most of it the session, CSRF and
    | remember-me cookies, so anything materially above that is worth knowing about.
    |
    | `max_response_header_bytes` is the size past which the application logs the
    | header breakdown itself. Set it to the front end's buffer, or to 0 to switch
    | the check off.
    |
    */

    'http' => [
        'max_response_header_bytes' => (int) env('MAX_RESPONSE_HEADER_BYTES', 4096),
    ],

    /*
    |---------------------------------------------------------------------------
    | Link previews and icons
    |---------------------------------------------------------------------------
    |
    | Used by resources/views/partials/head.blade.php for the browser tab icon and
    | for the Open Graph card that WhatsApp, Slack and LinkedIn render when someone
    | shares a link. `image` is resolved through asset(), so APP_URL (or ASSET_URL)
    | must be the public URL - scrapers cannot fetch a relative path or localhost.
    |
    */

    'meta' => [
        'title' => env('META_TITLE', 'PullLens - AI Code Review That Ships High-Quality Code'),
        'description' => env('META_DESCRIPTION', 'Self-hosted AI code reviewer that puts a tireless, senior-grade reviewer on every pull request - catching security, quality, and risk before merge. Source-available. Your infrastructure. Your control.'),
        'image' => env('META_IMAGE', 'og-image.png'),
        'image_alt' => env('META_IMAGE_ALT', 'PullLens - AI code review for every pull request'),
        'theme_color' => env('META_THEME_COLOR', '#111113'),

        // Ignored by Google, still read by some smaller engines and by assistant
        // crawlers that index on a simpler model. Free to keep, so it is kept.
        'keywords' => env('META_KEYWORDS', 'AI code review, pull request review, self-hosted code review, automated code review, security scanning, code quality, source-available, GitHub app'),

        // Open Graph wants language_TERRITORY, which app()->getLocale() does not
        // carry. Only ever read by a link-preview scraper, so it is a plain value
        // rather than something derived from the application locale.
        'locale' => env('META_LOCALE', 'en_US'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Landing page analytics
    |---------------------------------------------------------------------------
    |
    | A Matomo tracker for the public landing page, and nowhere else. It is never
    | rendered on a signed in page, so no analytics script is ever in a position
    | to observe a customer's repositories, findings or team data.
    |
    | Both values are required for the tracker to render at all, which makes an
    | installation with an empty .env silent by default rather than reporting to
    | somebody else's instance. `url` is the Matomo installation, with a trailing
    | slash; `site_id` is the numeric site inside it.
    |
    */

    'analytics' => [
        'matomo' => [
            'url' => env('MATOMO_URL'),
            'site_id' => env('MATOMO_SITE_ID'),
        ],
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
