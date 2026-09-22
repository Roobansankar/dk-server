<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_admin_can_create_a_review(): void
    {
        $this->actingAsToken($this->superadmin());

        $response = $this->postJson('/api/admin/reviews', [
            'reviewer_name' => 'Ananya R.',
            'rating' => 5,
            'review_text' => 'Loved the haircut, very professional staff.',
            'review_date' => '2026-08-01',
            'is_published' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reviewer_name', 'Ananya R.')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.is_published', true);

        $this->assertDatabaseHas('reviews', ['reviewer_name' => 'Ananya R.', 'rating' => 5]);
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->postJson('/api/admin/reviews', [
            'reviewer_name' => 'X',
            'rating' => 6,
            'review_text' => 'text',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    public function test_admin_can_upload_a_reviewer_avatar(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());

        $response = $this->postJson('/api/admin/reviews', [
            'reviewer_name' => 'Ananya R.',
            'rating' => 5,
            'review_text' => 'Great service.',
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $response->assertCreated();
        $path = Review::first()->reviewer_avatar_path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('reviews/', $path);
    }

    public function test_admin_can_edit_a_review(): void
    {
        $this->actingAsToken($this->superadmin());
        $review = Review::factory()->create(['reviewer_name' => 'Old Name']);

        $this->putJson("/api/admin/reviews/{$review->id}", ['reviewer_name' => 'New Name'])
            ->assertOk()->assertJsonPath('data.reviewer_name', 'New Name');
    }

    public function test_admin_can_publish_and_unpublish_a_review(): void
    {
        $this->actingAsToken($this->superadmin());
        $review = Review::factory()->unpublished()->create();

        $this->putJson("/api/admin/reviews/{$review->id}", ['is_published' => true])
            ->assertOk()->assertJsonPath('data.is_published', true);

        $this->putJson("/api/admin/reviews/{$review->id}", ['is_published' => false])
            ->assertOk()->assertJsonPath('data.is_published', false);
    }

    public function test_admin_can_delete_a_review(): void
    {
        Storage::fake('public');
        $this->actingAsToken($this->superadmin());
        $review = Review::factory()->create();

        $this->deleteJson("/api/admin/reviews/{$review->id}")->assertNoContent();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_admin_can_reorder_reviews(): void
    {
        $this->actingAsToken($this->superadmin());
        $a = Review::factory()->create(['sort_order' => 0]);
        $b = Review::factory()->create(['sort_order' => 1]);

        $this->postJson('/api/admin/reviews/reorder', ['ids' => [$b->id, $a->id]])->assertOk();

        $this->assertSame(0, $b->refresh()->sort_order);
        $this->assertSame(1, $a->refresh()->sort_order);
    }

    public function test_a_user_without_the_reviews_permission_cannot_manage_reviews(): void
    {
        $this->actingAsToken($this->userWith(['dashboard.view']));

        $this->postJson('/api/admin/reviews', [
            'reviewer_name' => 'X',
            'rating' => 5,
            'review_text' => 'text',
        ])->assertForbidden();
    }

    public function test_reviews_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/reviews')->assertUnauthorized();
    }
}
