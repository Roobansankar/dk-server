<?php

namespace Tests\Feature;

use App\Models\GalleryImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class GalleryTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_admin_can_upload_a_gallery_image(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());

        $response = $this->postJson('/api/admin/gallery', [
            'image' => UploadedFile::fake()->image('salon.jpg', 800, 600),
            'title' => 'Studio',
            'alt_text' => 'A styling chair',
        ]);

        $response->assertCreated()->assertJsonPath('data.title', 'Studio');

        $path = GalleryImage::first()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('gallery/', $path);
        $this->assertStringNotContainsString('salon', $path); // original filename not trusted
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/gallery', [
            'image' => UploadedFile::fake()->create('malware.php', 10, 'application/x-php'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_deleting_a_gallery_image_removes_the_file(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/gallery', [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ])->assertCreated();

        $image = GalleryImage::first();
        $path = $image->image_path;

        $this->deleteJson("/api/admin/gallery/{$image->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_public_gallery_is_readable_without_auth(): void
    {
        GalleryImage::factory()->count(3)->create();

        $this->getJson('/api/gallery')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'image_url', 'alt_text']]]);
    }
}
