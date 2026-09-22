<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class CustomerReviewSubmissionTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_a_guest_cannot_submit_a_review(): void
    {
        $this->postJson('/api/reviews', [
            'rating' => 5,
            'review_text' => 'Wonderful experience!',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_a_signed_in_customer_can_submit_a_review(): void
    {
        $customer = $this->customer(['name' => 'Priya Nair']);
        $this->actingAsToken($customer);

        $response = $this->postJson('/api/reviews', [
            'rating' => 5,
            'review_text' => 'Loved the haircut, very professional staff.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reviewer_name', 'Priya Nair')
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.source', 'customer')
            ->assertJsonPath('data.is_published', false);

        $this->assertDatabaseHas('reviews', [
            'user_id' => $customer->id,
            'source' => 'customer',
            'reviewer_name' => 'Priya Nair',
            'is_published' => false,
        ]);
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        $this->actingAsToken($this->customer());

        $this->postJson('/api/reviews', [
            'rating' => 6,
            'review_text' => 'Great service.',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    public function test_review_text_is_required(): void
    {
        $this->actingAsToken($this->customer());

        $this->postJson('/api/reviews', [
            'rating' => 4,
        ])->assertStatus(422)->assertJsonValidationErrors('review_text');
    }

    public function test_a_customer_cannot_submit_a_second_review(): void
    {
        $customer = $this->customer();
        $this->actingAsToken($customer);

        $this->postJson('/api/reviews', [
            'rating' => 5,
            'review_text' => 'First review.',
        ])->assertCreated();

        $this->postJson('/api/reviews', [
            'rating' => 4,
            'review_text' => 'Trying again.',
        ])->assertStatus(422)->assertJsonValidationErrors('review_text');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_user_id_reviewer_name_and_publish_status_cannot_be_spoofed_from_the_request_body(): void
    {
        $customer = $this->customer(['name' => 'Real Name']);
        $other = $this->customer();
        $this->actingAsToken($customer);

        $response = $this->postJson('/api/reviews', [
            'rating' => 5,
            'review_text' => 'Trying to spoof identity/approval.',
            'user_id' => $other->id,
            'reviewer_name' => 'Fake Name',
            'is_published' => true,
            'source' => 'google',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reviewer_name', 'Real Name')
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.source', 'customer');

        $this->assertDatabaseHas('reviews', ['user_id' => $customer->id, 'reviewer_name' => 'Real Name']);
        $this->assertDatabaseMissing('reviews', ['user_id' => $other->id]);
    }

    public function test_a_pending_customer_review_does_not_appear_on_the_public_endpoint(): void
    {
        $this->actingAsToken($this->customer());

        $this->postJson('/api/reviews', [
            'rating' => 5,
            'review_text' => 'Pending review.',
        ])->assertCreated();

        $this->getJson('/api/reviews')->assertOk()->assertJson(['data' => []]);
    }

    public function test_admin_can_approve_a_customer_submitted_review_and_it_then_appears_publicly(): void
    {
        $customer = $this->customer(['name' => 'Approved Customer']);
        $this->actingAsToken($customer);

        $created = $this->postJson('/api/reviews', [
            'rating' => 5,
            'review_text' => 'Great salon, will come again.',
        ])->assertCreated();

        $reviewId = $created->json('data.id');

        // Switch to an admin session and approve it exactly like an
        // admin-entered Google review — same endpoint, same flag.
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
