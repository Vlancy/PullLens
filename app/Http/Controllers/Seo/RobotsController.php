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
 * The file is an allow list, not a deny list. Listing the paths to keep out of -
 * the admin area, the reports, the password reset flow, the queue dashboard -
 * publishes a map of the application to anyone who fetches a file that is meant
 * to be fetched by everyone, and robots.txt is the first thing an attacker reads
 * for exactly that reason. Everything here is already visible in the landing
 * page's own HTML, so the file gives away nothing the page does not.
 *
 * `Disallow: /` closes the rest. A crawler matches the longest rule that applies
 * to a URL (RFC 9309), so an Allow below beats it for the paths it names and
 * nothing else needs saying.
 *
 * The AI crawlers get their own group saying the same thing. They honour the
 * wildcard group already; naming them makes the decision to let them read the
 * public pages explicit rather than accidental, and means tightening it later is
 * a one-line change here instead of a re-reading of the whole file.
 */
class RobotsController extends Controller
{
    /**
     * The only paths a crawler is invited to fetch.
     *
     * `/$` is the landing page and nothing below it. `/build` is the compiled CSS
     * and JavaScript: Google ranks what it renders, and a crawler that cannot
     * fetch the bundle renders an unstyled document. The rest are the icons and
     * the link-preview image, which a scraper needs to build a card.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        '/$',
        '/docs',
        '/build/',
        '/sitemap.xml',
        '/llms.txt',
        '/site.webmanifest',
        '/og-image.png',
        '/logo.png',
        '/apple-touch-icon.png',
        '/favicon.ico',
        '/favicon.png',
        '/favicon-16.png',
        '/favicon-32.png',
        '/icon-192.png',
        '/icon-512.png',
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

        $groups = ['User-agent: *'.PHP_EOL.$this->rules()];

        foreach (self::AI_CRAWLERS as $agent) {
            $groups[] = 'User-agent: '.$agent.PHP_EOL.$this->rules();
        }

        $sitemap = rtrim((string) config('app.url'), '/').'/sitemap.xml';

        return implode(PHP_EOL, $groups).PHP_EOL.'Sitemap: '.$sitemap.PHP_EOL;
    }

    /**
     * The Allow/Disallow block shared by every group.
     */
    private function rules(): string
    {
        $lines = [];

        foreach (self::ALLOWED as $path) {
            $lines[] = 'Allow: '.$path;
        }

        $lines[] = 'Disallow: /';

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
