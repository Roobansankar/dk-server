<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Haircut', 'Blow-Dry', 'Root Touch-Up', 'Balayage', 'Hair Spa',
            'Express Facial', 'Clean-Up', 'Head Massage', 'Beard Trim', 'Keratin',
        ]).' '.fake()->randomLetter();

        return [
            'service_category_id' => ServiceCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'duration_minutes' => fake()->randomElement([20, 30, 45, 60, 90, 120]),
            'price' => fake()->randomElement([350, 600, 900, 1200, 1600, 3200, 5500]),
            'advance_percentage' => fake()->randomElement([0, 10, 20, 25, 50]),
            'status' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }

    public function forCategory(ServiceCategory $category): static
    {
        return $this->state(fn () => ['service_category_id' => $category->id]);
    }
}
