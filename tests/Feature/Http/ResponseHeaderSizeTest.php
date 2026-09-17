<?php

use App\Models\Users\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
| Headers that outgrow the web server's buffer produce a 502 with nothing in the
| application log to explain it. The size is therefore checked here, where a change
| that inflates it fails a test instead of a deployment.
*/

function headerBytes(TestResponse $response): int
{
    $bytes = 0;

    foreach ($response->headers->allPreserveCase() as $name => $values) {
        foreach ($values as $value) {
            $bytes += strlen((string) $name) + strlen((string) $value) + 4;
        }
    }

    return $bytes;
}

it('keeps a signed in page inside the smallest common header buffer', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    auth()->login($user, true);

    $response = $this->actingAs($user)->get('/tasks');

    expect($response->status())->toBe(200)
        ->and(headerBytes($response))->toBeLessThan(4096);
});

it('keeps the guest pages inside the buffer too', function () {
    // The login page used to send 4.2 KB of headers, 2.9 KB of it a preload `Link`
    // header, which is past the 4 KB buffer a FastCGI front end commonly ships with.
    // It matters most here: with HOMEPAGE_LOGIN on, this page is the home page.
    foreach (['/login', '/'] as $path) {
        $response = $this->get($path);

        expect(headerBytes($response))->toBeLessThan(4096, "{$path} sends too many header bytes");
    }
});

it('logs the header breakdown when the budget is exceeded', function () {
    config()->set('pulllens.http.max_response_header_bytes', 1);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'header buffer budget')
                && $context['total_bytes'] > 0
                && $context['headers'] !== [];
        });

    $this->get('/login')->assertSuccessful();
});
