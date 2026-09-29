<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * On hosting where `php artisan storage:link` was never run (no SSH access
 * to create it, and it wouldn't survive a copy-based redeploy anyway),
 * Apache's own .htaccess falls through to index.php for any "/storage/..."
 * path that isn't a real file — routes/web.php's own "storage/{path}" route
 * is what actually answers it there (BinaryFileResponse, not Laravel's
 * built-in FilesystemServiceProvider one — that's a plain StreamedResponse
 * with no Range support, which leaves a <video> stuck at 0:00 in every
 * browser). Locally, where the symlink exists, the webserver serves the
 * real file first and this route is never reached; these tests dispatch
 * straight into the router, the same as a request the symlink didn't catch.
 */
class StorageFallbackRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_an_existing_file_from_the_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('stylists/example.webp', 'fake-webp-bytes');

        $response = $this->get('/storage/stylists/example.webp');

        $response->assertOk();
        $this->assertSame('fake-webp-bytes', $response->streamedContent());
    }

    public function test_it_serves_a_real_uploaded_image_with_its_real_content_type(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('photo.jpg', 10, 10);
        $stored = $file->store('stylists', 'public');

        $response = $this->get('/storage/'.$stored);

        $response->assertOk();
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }

    public function test_a_missing_file_is_a_plain_404_not_an_error(): void
    {
        Storage::fake('public');

        $this->get('/storage/stylists/does-not-exist.webp')->assertNotFound();
    }

    public function test_it_cannot_be_used_to_read_outside_the_public_disk(): void
    {
        Storage::fake('public');

        // Laravel/Flysystem normalises ".." segments away, so this can only
        // ever resolve back inside the public disk's own root — never the
        // private disk or the app's source files.
        $this->get('/storage/../../.env')->assertNotFound();
        $this->get('/storage/..%2F..%2F.env')->assertNotFound();
    }

    // --- Range requests (what a <video> needs to play at all) -------------------

    public function test_a_byte_range_request_gets_back_only_that_slice_as_206(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('videos/clip.mp4', '0123456789');

        $response = $this->withHeaders(['Range' => 'bytes=2-5'])->get('/storage/videos/clip.mp4');

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 2-5/10');
        $response->assertHeader('Content-Length', '4');
        $response->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_a_request_with_no_range_header_still_gets_the_whole_file_with_200(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('videos/clip.mp4', '0123456789');

        $response = $this->get('/storage/videos/clip.mp4');

        $response->assertOk();
        $response->assertHeader('Accept-Ranges', 'bytes');
        $response->assertHeader('Content-Length', '10');
    }

    public function test_media_is_served_inline_not_as_a_forced_download(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('videos/clip.mp4', 'bytes');

        $this->get('/storage/videos/clip.mp4')
            ->assertHeader('Content-Disposition', 'inline; filename=clip.mp4');
    }
}
