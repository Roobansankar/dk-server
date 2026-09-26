<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Gentle Cleansing Shampoo', 'Everyday Conditioner', 'Repair Hair Mask',
            'Sea Salt Spray', 'Matte Texture Clay', 'Smoothing Serum',
            'Daily Facial Cleanser', 'Hydrating Moisturiser', 'Conditioning Beard Oil',
        ]).' '.fake()->randomLetter();

        $mrp = fake()->randomElement([350, 450, 600, 850, 1200, 1800]);
        $selling = $mrp - fake()->randomElement([0, 0, 50, 100, 150]);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(10),
            'image_path' => null,
            'mrp' => $mrp,
            'selling_price' => max(0, $selling),
            'gst_inclusive' => fake()->boolean(80),
            'status' => true,
            'is_featured' => false,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['is_featured' => true, 'featured_at' => now()]);
    }
}
