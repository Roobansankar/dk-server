<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_published_reviews_are_returned(): void
    {
        Review::factory()->create(['reviewer_name' => 'Published One']);
        Review::factory()->unpublished()->create(['reviewer_name' => 'Draft Hidden']);

        $response = $this->getJson('/api/reviews')->assertOk();

        $names = collect($response->json('data'))->pluck('reviewer_name');
        $this->assertTrue($names->contains('Published One'));
        $this->assertFalse($names->contains('Draft Hidden'));
    }

    public function test_reviews_are_returned_in_sort_order(): void
    {
        Review::factory()->create(['reviewer_name' => 'Second', 'sort_order' => 1]);
        Review::factory()->create(['reviewer_name' => 'First', 'sort_order' => 0]);

        $response = $this->getJson('/api/reviews')->assertOk();

        $names = collect($response->json('data'))->pluck('reviewer_name')->values();
        $this->assertSame(['First', 'Second'], $names->all());
    }

    public function test_empty_state_when_no_reviews_are_published(): void
    {
        Review::factory()->unpublished()->create();

        $this->getJson('/api/reviews')->assertOk()->assertJson(['data' => []]);
    }

    public function test_public_reviews_endpoint_requires_no_authentication(): void
    {
        Review::factory()->create();

        $this->getJson('/api/reviews')->assertOk();
    }
}
