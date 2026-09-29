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
 * path that isn't a real file — Laravel's own built-in route (registered by
 * FilesystemServiceProvider because the 'public' disk sets 'serve' => true
 * in config/filesystems.php) is what actually answers it there. Locally,
 * where the symlink exists, the webserver serves the real file first and
 * this route is never reached; these tests dispatch straight into the
 * router, the same as a request the symlink didn't catch.
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
}
