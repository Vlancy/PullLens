<?php

use App\Support\Seo\LandingPageFaq;

/*
| /llms.txt is the plain-text description an assistant reads instead of parsing
| the landing page's markup. It has to exist, and it has to say the same things.
*/

test('llms.txt describes the product in plain text', function () {
    $body = $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->getContent();

    expect($body)
        ->toStartWith('# '.config('app.name'))
        ->toContain('> '.config('pulllens.meta.description'))
        ->toContain('MIT licence with the Commons Clause');
});

test('llms.txt links the guide at absolute urls', function () {
    config()->set('app.url', 'https://pulllens.example.com');

    expect($this->get('/llms.txt')->getContent())
        ->toContain('https://pulllens.example.com/docs')
        ->toContain('https://pulllens.example.com/docs/guide/installation.html');
});

test('llms.txt carries every question the landing page answers', function () {
    $body = $this->get('/llms.txt')->getContent();

    foreach (LandingPageFaq::all() as $entry) {
        expect($body)
            ->toContain('### '.$entry['question'])
            ->toContain($entry['answer']);
    }
});

test('a private instance publishes no llms.txt', function () {
    config()->set('pulllens.homepage_login', true);

    $this->get('/llms.txt')->assertNotFound();
});
