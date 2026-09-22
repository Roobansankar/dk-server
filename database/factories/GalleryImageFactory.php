<?php

namespace Database\Factories;

use App\Models\GalleryImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryImage>
 */
class GalleryImageFactory extends Factory
{
    protected $model = GalleryImage::class;

    public function definition(): array
    {
        return [
            'title' => fake()->optional()->words(2, true),
            'image_path' => 'gallery/'.fake()->uuid().'.jpg',
            'alt_text' => fake()->sentence(4),
            'category' => fake()->optional()->randomElement(['salon', 'work', 'team']),
            'status' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
