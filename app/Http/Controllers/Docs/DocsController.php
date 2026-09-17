<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the pre-built HTML user guide that lives in `docs/`.
 *
 * The guide is deliberately plain HTML with no build step so it can be read
 * straight from a clone, which is why it sits outside `public/`. Rather than
 * duplicating four megabytes of screenshots into the web root on every deploy,
 * or asking every operator to add an alias to their web server, the files are
 * streamed from here. Documentation traffic is a rounding error next to the
 * webhooks, so the cost of going through PHP is not worth engineering away.
 *
 * Only the extensions below are served, and the resolved path is checked to be
 * inside `docs/` after symlinks are collapsed, so no request can walk out of it.
 */
class DocsController extends Controller
{
    /**
     * Extension => Content-Type. Anything not listed here is a 404, so a stray
     * `.php`, `.py` or `.env` inside the folder can never be handed out.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    /**
     * Pages change when the guide is rebuilt; the screenshots essentially never do.
     */
    private const MAX_AGE_PAGE = 300;

    private const MAX_AGE_ASSET = 604800;

    /**
     * Stream one file from the guide.
     */
    public function __invoke(string $path = ''): BinaryFileResponse
    {
        $relative = trim($path, '/');

        if ($relative === '' || str_ends_with($path, '/')) {
            $relative = ltrim($relative.'/index.html', '/');
        }

        $root = realpath(base_path('docs'));
        $file = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$relative);

        // A missing file and an attempt to escape the folder are the same answer:
        // telling them apart would confirm what exists outside it.
        if ($root === false || $file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (! isset(self::TYPES[$extension])) {
            abort(404);
        }

        $isPage = $extension === 'html';

        return response()->file($file, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => 'public, max-age='.($isPage ? self::MAX_AGE_PAGE : self::MAX_AGE_ASSET),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
