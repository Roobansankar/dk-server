<?php

namespace Tests\Feature;

use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Homepage video uploads (Admin → Videos): stored exactly as uploaded, no
 * ffmpeg transcoding and no generated thumbnail (see VideoUploader) — shared
 * hosting can't reliably offer ffmpeg/proc_open, so there's nothing here to
 * fake or skip; a plain file store is testable directly with the real class.
 */
class VideoTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());
    }

    public function test_a_video_is_stored_exactly_as_uploaded_with_no_thumbnail(): void
    {
        $file = UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4');

        $response = $this->postJson('/api/admin/videos', [
            'title' => 'Studio walk-through',
            'video' => $file,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Studio walk-through');
        $this->assertNull($response->json('data.thumbnail_url'));

        $video = Video::firstOrFail();
        $this->assertStringEndsWith('.mp4', $video->video_path);
        $this->assertNull($video->thumbnail_path);

        Storage::disk('public')->assertExists($video->video_path);
    }

    public function test_updating_the_video_file_replaces_the_old_one(): void
    {
        $video = Video::create([
            'video_path' => 'videos/old.mp4',
            'thumbnail_path' => 'videos/old-thumb.webp',
        ]);
        Storage::disk('public')->put('videos/old.mp4', 'old bytes');
        Storage::disk('public')->put('videos/old-thumb.webp', 'old thumb');

        $newFile = UploadedFile::fake()->create('new.mov', 1024, 'video/quicktime');

        $this->putJson("/api/admin/videos/{$video->id}", [
            'video' => $newFile,
        ])->assertOk();

        $video->refresh();
        $this->assertStringEndsWith('.mov', $video->video_path);
        $this->assertNull($video->thumbnail_path);
        Storage::disk('public')->assertExists($video->video_path);
        Storage::disk('public')->assertMissing('videos/old.mp4');
        Storage::disk('public')->assertMissing('videos/old-thumb.webp');
    }

    public function test_deleting_a_video_removes_its_stored_file(): void
    {
        $video = Video::create(['video_path' => 'videos/gone.mp4', 'thumbnail_path' => null]);
        Storage::disk('public')->put('videos/gone.mp4', 'bytes');

        $this->deleteJson("/api/admin/videos/{$video->id}")->assertNoContent();

        Storage::disk('public')->assertMissing('videos/gone.mp4');
        $this->assertNull(Video::withTrashed()->find($video->id));
    }

    public function test_an_oversized_video_is_still_rejected_without_needing_compression(): void
    {
        config(['salon.video_uploads.max_kb' => 100]);
        $file = UploadedFile::fake()->create('huge.mp4', 200, 'video/mp4');

        $this->postJson('/api/admin/videos', ['video' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('video');

        $this->assertSame(0, Video::count());
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('not-a-video.txt', 10, 'text/plain');

        $this->postJson('/api/admin/videos', ['video' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('video');
    }
}
