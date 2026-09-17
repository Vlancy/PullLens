<?php

/*
| The sitemap is generated from the guide on disk, so these guard the generation
| rather than a list of URLs somebody would otherwise have to keep in step.
*/

test('the sitemap is well formed xml', function () {
    $body = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
        ->getContent();

    $xml = simplexml_load_string($body);

    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('urlset');
});

test('the landing page is the first and highest priority entry', function () {
    config()->set('app.url', 'https://pulllens.example.com');

    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());

    expect((string) $xml->url[0]->loc)->toBe('https://pulllens.example.com/')
        ->and((string) $xml->url[0]->priority)->toBe('1.0');
});

test('every page of the user guide is listed', function () {
    config()->set('app.url', 'https://pulllens.example.com');

    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());

    // iterator_to_array() would collapse the list: every element is named `url`,
    // so they all share one key.
    $locations = [];

    foreach ($xml->url as $url) {
        $locations[] = (string) $url->loc;
    }

    // Sampled rather than exhaustive: the point is that the guide is discovered
    // from disk, not that this list stays in step with every page added to it.
    expect($locations)
        ->toContain('https://pulllens.example.com/docs/guide/index.html')
        ->toContain('https://pulllens.example.com/docs/guide/installation.html')
        ->toContain('https://pulllens.example.com/docs/guide/troubleshooting.html');

    $pagesOnDisk = count(glob(base_path('docs/guide/*.html')) ?: []);

    // Home plus the guide. docs/index.html is excluded on purpose: it is a
    // meta-refresh onto docs/guide/index.html, which is already listed.
    expect(count($locations))->toBe($pagesOnDisk + 1)
        ->and($locations)->not->toContain('https://pulllens.example.com/docs/index.html');
});

test('lastmod reflects the file rather than the deploy', function () {
    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());

    $expected = gmdate('Y-m-d', (int) filemtime(base_path('docs/guide/index.html')));

    $lastmod = null;

    foreach ($xml->url as $url) {
        if (str_ends_with((string) $url->loc, '/docs/guide/index.html')) {
            $lastmod = (string) $url->lastmod;
        }
    }

    expect($lastmod)->toBe($expected);
});

test('a private instance has no sitemap', function () {
    config()->set('pulllens.homepage_login', true);

    $this->get('/sitemap.xml')->assertNotFound();
});
