<?php

/*
| The user guide is served out of `docs/`, which sits outside the web root. That
| makes the path handling the security boundary: these tests pin the redirect,
| the allowed file types, and the fact that no request can climb out of the folder.
*/

it('redirects the docs root to the guide', function () {
    $this->get('/docs')->assertRedirect('docs/guide/index.html');
});

it('serves a guide page', function () {
    $response = $this->get('/docs/guide/index.html');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
    expect($response->streamedContent())->toContain('PullLens');
});

it('serves a guide asset', function () {
    $this->get('/docs/guide/assets/doc.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=utf-8');
});

it('serves a screenshot used by the landing page', function () {
    $this->get('/docs/images/tour.gif')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/gif');
});

it('refuses file types that are not part of the guide', function () {
    $this->get('/docs/build-guide.py')->assertNotFound();
});

it('refuses to walk out of the docs folder', function () {
    $this->get('/docs/../.env')->assertNotFound();
    $this->get('/docs/%2e%2e/.env')->assertNotFound();
    $this->get('/docs/guide/../../composer.json')->assertNotFound();
});
