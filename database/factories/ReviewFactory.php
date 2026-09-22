<?php

namespace Database\Factories;

use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'reviewer_name' => fake()->name(),
            'rating' => fake()->numberBetween(3, 5),
            'review_text' => fake()->paragraph(2),
            'review_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'reviewer_avatar_path' => null,
            'is_published' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }
}
