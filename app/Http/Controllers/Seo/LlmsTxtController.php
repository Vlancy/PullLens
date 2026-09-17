<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Support\Seo\LandingPageFaq;
use Illuminate\Http\Response;

/**
 * Serves /llms.txt.
 *
 * The landing page is a large React document: an assistant that fetches it gets
 * a wall of markup and has to infer the product from it. This is the same
 * information written for that reader - what PullLens is, what it costs, what it
 * runs on, and the answers to the questions people actually ask - in plain
 * Markdown at a conventional address.
 *
 * It is a convention rather than a standard, and no crawler is obliged to read
 * it. It costs one route and stays correct because the questions come from the
 * same list the landing page renders.
 */
class LlmsTxtController extends Controller
{
    /**
     * Render the file.
     */
    public function __invoke(): Response
    {
        return response($this->body(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Build the document.
     */
    private function body(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $name = (string) config('app.name');

        $lines = [
            '# '.$name,
            '',
            '> '.config('pulllens.meta.description'),
            '',
            'PullLens is source-available under the MIT licence with the Commons Clause.',
            'It is self-hosted: the application, its database and every review stay on',
            'your own infrastructure. You bring your own AI provider key and pay that',
            'provider directly, at cost.',
            '',
            '## Documentation',
            '',
            '- [User guide]('.$base.'/docs): every screen and setting, with screenshots.',
            '- [Installation]('.$base.'/docs/guide/installation.html): bringing an instance up.',
            '- [Connecting GitHub]('.$base.'/docs/guide/github.html): the GitHub App and webhooks.',
            '- [AI providers]('.$base.'/docs/guide/ai-providers.html): the twelve supported providers.',
            '- [Troubleshooting]('.$base.'/docs/guide/troubleshooting.html): when something does not work.',
            '- [Source]('.'https://github.com/Vlancy/PullLens): the repository and the licence.',
            '',
            '## Questions and answers',
            '',
        ];

        foreach (LandingPageFaq::all() as $entry) {
            $lines[] = '### '.$entry['question'];
            $lines[] = '';
            $lines[] = $entry['answer'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
