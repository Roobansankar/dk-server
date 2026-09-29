<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Serves uploaded media (stylist/product/gallery photos, homepage videos,
 * etc.) whenever the `public/storage` symlink `php artisan storage:link`
 * normally creates isn't there to let the webserver hand the file back
 * directly — e.g. shared hosting deployed by copying files rather than
 * running artisan commands, where the symlink can't be created and doesn't
 * survive a redeploy either way. Apache's own .htaccess already falls
 * through to index.php for any path that isn't a real file on disk, so this
 * route is what actually answers "/storage/..." there; locally, where the
 * symlink does exist, the webserver serves the real file first and this
 * route is never reached.
 *
 * BinaryFileResponse (not Storage::response(), which is a plain
 * StreamedResponse — see the 'public' disk's config('filesystems') comment
 * for why that doesn't work here) so ->prepare($request) answers a video's
 * Range requests with real 206 Partial Content: every browser needs that to
 * play a video at all, not just to seek — without it playback never leaves
 * 0:00. Only ever reads the public media disk — never the private one.
 */
Route::get('/storage/{path}', function (Request $request, string $path) {
    $disk = Storage::disk(config('salon.media_disk'));

    try {
        abort_unless($disk->exists($path), 404);

        $response = new BinaryFileResponse($disk->path($path));
        $response->headers->set('Content-Type', $disk->mimeType($path) ?: 'application/octet-stream');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response->prepare($request);
    } catch (PathTraversalDetected) {
        // "../" etc. in $path — Flysystem itself refuses to even check
        // existence for these; same result either way, a clean 404.
        abort(404);
    }
})->where('path', '.*');
