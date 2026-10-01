<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/** A visitor's own review — submitted without an account, moderated by staff. */
class CustomerReviewSubmissionTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_a_guest_can_submit_a_review(): void
    {
        $response = $this->postJson('/api/reviews', [
            'reviewer_name' => 'Priya Nair',
            'rating' => 5,
            'review_text' => 'Loved the haircut, very professional staff.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reviewer_name', 'Priya Nair')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.source', 'customer')
            ->assertJsonPath('data.is_published', false);

        $this->assertDatabaseHas('reviews', [
            'user_id' => null,
            'source' => 'customer',
            'reviewer_name' => 'Priya Nair',
            'is_published' => false,
        ]);
    }

    public function test_a_name_is_required(): void
    {
        $this->postJson('/api/reviews', [
            'rating' => 5,
            'review_text' => 'Great service.',
        ])->assertStatus(422)->assertJsonValidationErrors('reviewer_name');

        $this->postJson('/api/reviews', [
            'reviewer_name' => '   ',
            'rating' => 5,
            'review_text' => 'Great service.',
        ])->assertStatus(422)->assertJsonValidationErrors('reviewer_name');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        $this->postJson('/api/reviews', [
            'reviewer_name' => 'Priya Nair',
            'rating' => 6,
            'review_text' => 'Great service.',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    public function test_review_text_is_required(): void
    {
        $this->postJson('/api/reviews', [
            'reviewer_name' => 'Priya Nair',
            'rating' => 4,
        ])->assertStatus(422)->assertJsonValidationErrors('review_text');
    }

    public function test_owner_source_and_publish_status_cannot_be_spoofed_from_the_request_body(): void
    {
        $someone = $this->admin();

        $response = $this->postJson('/api/reviews', [
            'reviewer_name' => 'Real Name',
            'rating' => 5,
            'review_text' => 'Trying to self-publish.',
            'user_id' => $someone->id,
            'is_published' => true,
            'source' => 'google',
            'sort_order' => 0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reviewer_name', 'Real Name')
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.source', 'customer');

        $this->assertDatabaseHas('reviews', ['reviewer_name' => 'Real Name', 'user_id' => null, 'is_published' => false]);
    }

    public function test_a_pending_review_does_not_appear_on_the_public_endpoint(): void
    {
        $this->postJson('/api/reviews', [
            'reviewer_name' => 'Priya Nair',
            'rating' => 5,
            'review_text' => 'Pending review.',
        ])->assertCreated();

        $this->getJson('/api/reviews')->assertOk()->assertJson(['data' => []]);
    }

    public function test_admin_can_approve_a_submitted_review_and_it_then_appears_publicly(): void
    {
        $created = $this->postJson('/api/reviews', [
            'reviewer_name' => 'Approved Customer',
            'rating' => 5,
            'review_text' => 'Great salon, will come again.',
        ])->assertCreated();

        $reviewId = $created->json('data.id');

        // Moderation is still staff-only.
        $this->putJson("/api/admin/reviews/{$reviewId}", ['is_published' => true])->assertUnauthorized();

        // Approve it exactly like an admin-entered Google review — same
        // endpoint, same flag.
        $this->actingAsToken($this->superadmin());

        $this->putJson("/api/admin/reviews/{$reviewId}", ['is_published' => true])
            ->assertOk()
            ->assertJsonPath('data.is_published', true);

        $public = $this->getJson('/api/reviews')->assertOk();
        $names = collect($public->json('data'))->pluck('reviewer_name');
        $this->assertTrue($names->contains('Approved Customer'));
    }

    public function test_existing_manually_entered_google_reviews_are_unaffected(): void
    {
        Review::factory()->create(['reviewer_name' => 'Google Reviewer', 'source' => 'google']);

        $response = $this->getJson('/api/reviews')->assertOk();

        $names = collect($response->json('data'))->pluck('reviewer_name');
        $this->assertTrue($names->contains('Google Reviewer'));
    }
}
