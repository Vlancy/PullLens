<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watches how large the response header block grows.
 *
 * A FastCGI front end reads the headers into a fixed buffer and answers a 502 when
 * they do not fit, which is reported as "upstream sent too big header" in the web
 * server log and as a blank error page to the user - with nothing in the application
 * log to explain it. This records the breakdown while the request is still inside
 * PHP, so the cause is named rather than guessed at.
 *
 * It is deliberately observational: every header a page sends is one the page needs,
 * and dropping one to fit a buffer would trade a visible failure for an invisible one.
 */
class GuardResponseHeaderSize
{
    /** Smallest header buffer in common front-end defaults, in bytes. */
    private const DEFAULT_BUDGET = 4096;

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $budget = (int) config('pulllens.http.max_response_header_bytes', self::DEFAULT_BUDGET);

        if ($budget <= 0) {
            return $response;
        }

        $sizes = $this->sizes($response);
        $total = array_sum($sizes);

        if ($total > $budget) {
            Log::warning('Response headers exceed the front-end header buffer budget.', [
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'total_bytes' => $total,
                'budget_bytes' => $budget,
                'headers' => $sizes,
            ]);
        }

        return $response;
    }

    /**
     * Bytes each header contributes to the block, name and CRLF included.
     *
     * @return array<string, int>
     */
    private function sizes(Response $response): array
    {
        $sizes = [];

        foreach ($response->headers->allPreserveCase() as $name => $values) {
            foreach ($values as $value) {
                // A repeated header (Set-Cookie) is counted once per value, keyed by the
                // cookie name so the log points at the specific cookie that grew.
                $key = count($values) > 1 ? $name.' ('.strtok((string) $value, '=').')' : $name;
                $sizes[$key] = strlen((string) $name) + strlen((string) $value) + 4;
            }
        }

        arsort($sizes);

        return $sizes;
    }
}
