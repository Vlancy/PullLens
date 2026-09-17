<?php

use App\Support\Seo\LandingPageFaq;

/*
| Indexability and structured data. Both are invisible in a browser, so nothing
| but a test notices when a change quietly deindexes the landing page or leaves
| the FAQ markup describing answers that are no longer on it.
*/

/**
 * Pull the props Inertia embedded in a rendered page.
 *
 * @return array<string, mixed>
 */
function inertiaPropsFrom(string $html): array
{
    expect(preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $matches))->toBe(1);

    return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
}

/**
 * Pull the JSON-LD graph out of a rendered page.
 *
 * @return array<string, mixed>|null
 */
function structuredDataFrom(string $html): ?array
{
    if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches) !== 1) {
        return null;
    }

    return json_decode(html_entity_decode($matches[1]), true);
}

test('the title and description are in the response, not written after hydration', function () {
    // A link-preview scraper and most assistant crawlers read the HTML without
    // running React, so metadata that only Inertia writes does not exist for them.
    $this->get('/')
        ->assertOk()
        ->assertSee('<title>'.config('pulllens.meta.title').'</title>', escape: false)
        ->assertSee('name="description" content="'.config('pulllens.meta.description').'"', escape: false)
        ->assertSee('name="keywords"', escape: false);
});

test('the description is written exactly once', function () {
    expect(substr_count($this->get('/')->getContent(), 'name="description"'))->toBe(1);
});

test('the landing page is indexable and declares its canonical url', function () {
    config()->set('app.url', 'https://pulllens.example.com');

    $this->get('/')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://pulllens.example.com/">', escape: false)
        ->assertSee('content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1"', escape: false);
});

test('every other page tells crawlers to stay out', function (string $path) {
    $this->get($path)
        ->assertSee('content="noindex, nofollow"', escape: false)
        ->assertDontSee('rel="canonical"', escape: false);
})->with(['/login', '/forgot-password']);

test('structured data is published on the landing page only', function () {
    expect(structuredDataFrom($this->get('/')->getContent()))->not->toBeNull();
    expect(structuredDataFrom($this->get('/login')->getContent()))->toBeNull();
});

test('the graph describes the product and its publisher', function () {
    config()->set('app.url', 'https://pulllens.example.com');

    $graph = collect(structuredDataFrom($this->get('/')->getContent())['@graph'])
        ->keyBy('@type');

    expect($graph->keys()->all())
        ->toContain('Organization', 'WebSite', 'WebPage', 'SoftwareApplication', 'FAQPage');

    $software = $graph['SoftwareApplication'];

    expect($software['name'])->toBe(config('app.name'))
        ->and($software['applicationCategory'])->toBe('DeveloperApplication')
        ->and($software['isAccessibleForFree'])->toBeTrue()
        ->and($software['offers']['price'])->toBe('0')
        ->and($software['url'])->toBe('https://pulllens.example.com/')
        ->and($software['publisher']['@id'])->toBe($graph['Organization']['@id']);
});

test('the faq markup and the faq the page renders are the same answers', function () {
    $html = $this->get('/')->getContent();

    $faq = collect(structuredDataFrom($html)['@graph'])->firstWhere('@type', 'FAQPage');

    expect($faq['mainEntity'])->toHaveCount(count(LandingPageFaq::all()));

    foreach (LandingPageFaq::all() as $index => $entry) {
        expect($faq['mainEntity'][$index]['name'])->toBe($entry['question'])
            ->and($faq['mainEntity'][$index]['acceptedAnswer']['text'])->toBe($entry['answer']);
    }

    // And the same answers reach the page itself. A FAQPage describing answers a
    // visitor cannot find is a structured data violation, not a shortcut - so the
    // check reads the props Inertia actually handed the component.
    expect(inertiaPropsFrom($html)['faq'])->toBe(LandingPageFaq::all());
});

test('the link preview card is absolute and sized', function () {
    // asset() resolves against the URL generator's root, which is fixed when the
    // application boots - so the assertion asks it for the same URL a scraper
    // would be given rather than overriding configuration after the fact.
    $this->get('/')
        ->assertSee('property="og:image" content="'.asset('og-image.png').'"', escape: false)
        ->assertSee('property="og:image:width" content="1200"', escape: false)
        ->assertSee('property="og:locale" content="en_US"', escape: false)
        ->assertSee('name="twitter:card" content="summary_large_image"', escape: false);
});
