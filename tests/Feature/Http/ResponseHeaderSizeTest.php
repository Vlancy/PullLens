<?php

use App\Models\Users\User;
use Illuminate\Support\Facades\Log;

/*
| Headers that outgrow the web server's buffer produce a 502 with nothing in the
| application log to explain it. The size is therefore checked here, where a change
| that inflates it fails a test instead of a deployment.
*/

it('keeps a signed in page inside the smallest common header buffer', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    auth()->login($user, true);

    $response = $this->actingAs($user)->get('/tasks');

    $bytes = 0;

    foreach ($response->headers->allPreserveCase() as $name => $values) {
        foreach ($values as $value) {
            $bytes += strlen((string) $name) + strlen((string) $value) + 4;
        }
    }

    expect($response->status())->toBe(200)
        ->and($bytes)->toBeLessThan(4096);
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
