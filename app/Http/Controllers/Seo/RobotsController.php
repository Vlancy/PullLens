<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * Serves /robots.txt.
 *
 * Generated rather than shipped as a static file because two of its answers
 * depend on configuration: an instance running with HOMEPAGE_LOGIN on has no
 * public face at all and must be withdrawn from every index, and the Sitemap
 * line has to carry the deployment's own absolute URL.
 *
 * Only the landing page and the user guide are public. Everything else is either
 * behind authentication or a form endpoint, and a crawler that walks it wastes
 * its budget on pages it will be redirected away from - and puts the login and
 * password reset screens in a search index, which is a mild but free-to-avoid
 * disclosure of what the instance runs.
 *
 * The AI crawlers get their own group saying the same thing. They honour the
 * wildcard group already; naming them makes the decision to let them read the
 * public pages explicit rather than accidental, and means tightening it later is
 * a one-line change here instead of a re-reading of the whole file.
 */
class RobotsController extends Controller
{
    /**
     * Path prefixes no crawler should follow: the authenticated application, the
     * credential screens and the operator dashboards.
     *
     * `/build` is deliberately absent. Google renders the landing page before it
     * judges it, and a crawler that cannot fetch the compiled CSS and JavaScript
     * renders an unstyled document - blocking the build output to tidy up the list
     * costs the page the ranking the rest of this file exists to earn.
     *
     * @var list<string>
     */
    private const DISALLOWED = [
        '/admin',
        '/assistant',
        '/dashboard',
        '/email/',
        '/findings',
        '/forgot-password',
        '/horizon',
        '/login',
        '/logout',
        '/passkeys/',
        '/register',
        '/reports',
        '/repositories',
        '/reset-password',
        '/sanctum/',
        '/settings',
        '/storage/',
        '/tasks',
        '/telescope',
        '/two-factor-challenge',
        '/up',
        '/user/',
        '/webhooks/',
    ];

    /**
     * The assistants whose crawlers decide whether the product can be cited in a
     * generated answer. Listed so the permission is deliberate and visible.
     *
     * @var list<string>
     */
    private const AI_CRAWLERS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-User',
        'Claude-SearchBot',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'Applebot-Extended',
        'meta-externalagent',
        'Bingbot',
        'DuckDuckBot',
        'cohere-ai',
    ];

    /**
     * Render the file.
     */
    public function __invoke(): Response
    {
        return response(
            $this->body(),
            200,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Build the file contents.
     */
    private function body(): string
    {
        if (config('pulllens.homepage_login')) {
            // No landing page, no guide, nothing a visitor is meant to reach.
            return "# This instance is private.\nUser-agent: *\nDisallow: /\n";
        }

        $groups = ["User-agent: *\n".$this->rules()];

        foreach (self::AI_CRAWLERS as $agent) {
            $groups[] = "User-agent: {$agent}\n".$this->rules();
        }

        $sitemap = rtrim((string) config('app.url'), '/').'/sitemap.xml';

        return implode("\n", $groups)."\nSitemap: {$sitemap}\n";
    }

    /**
     * The Allow/Disallow block shared by every group.
     *
     * `Allow: /$` matches the home page and nothing below it, so the landing page
     * stays crawlable even though several of the prefixes below sit at the root.
     */
    private function rules(): string
    {
        $lines = ['Allow: /$', 'Allow: /docs'];

        foreach (self::DISALLOWED as $path) {
            $lines[] = "Disallow: {$path}";
        }

        return implode("\n", $lines)."\n";
    }
}
