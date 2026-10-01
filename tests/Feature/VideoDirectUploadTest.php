<?php

namespace Tests\Feature;

use App\Support\VideoUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * VIDEO_DRIVER=direct (shared hosting without ffmpeg, e.g. Hostinger):
 * uploads are stored exactly as-is — no shell calls, no transcoding, no
 * poster — so video uploads keep working where ffmpeg can't exist.
 */
class VideoDirectUploadTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['salon.video_uploads.driver' => 'direct']);
        config(['salon.media_disk' => 'public']);
        Storage::fake('public');
    }

    public function test_direct_driver_is_selected(): void
    {
        $this->assertSame('direct', VideoUploader::driver());
    }

    public function test_direct_store_keeps_the_original_without_touching_a_shell(): void
    {
        $file = UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4');

        $stored = VideoUploader::store($file, 'videos');

        $this->assertStringEndsWith('.mp4', $stored['video_path']);
        $this->assertNull($stored['thumbnail_path']);
        $this->assertTrue(Storage::disk('public')->exists($stored['video_path']));
    }

    public function test_direct_store_rejects_a_disallowed_extension(): void
    {
        $this->expectException(ValidationException::class);

        VideoUploader::store(UploadedFile::fake()->create('clip.exe', 100, 'application/octet-stream'), 'videos');
    }

    public function test_status_endpoint_reports_the_direct_driver(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->getJson('/api/admin/videos/status')
            ->assertOk()
            ->assertJsonPath('data.driver', 'direct')
            ->assertJsonPath('data.compression', false);
    }

    public function test_video_upload_works_end_to_end_without_ffmpeg(): void
    {
        $this->actingAsToken($this->superadmin());

        $response = $this->post('/api/admin/videos', [
            'title' => 'Direct clip',
            'video' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('data.thumbnail_url'));
        $this->assertStringEndsWith('.mp4', $response->json('data.video_url'));
    }
}
