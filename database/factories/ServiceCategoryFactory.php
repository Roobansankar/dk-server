<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Hair', 'Skin', 'Colour', 'Treatment', 'Massage', 'Grooming', 'Makeup', 'Nails',
        ]).' '.fake()->randomLetter();

        return [
            'gender' => fake()->randomElement(ServiceCategory::GENDERS),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'category_type' => fake()->randomElement(ServiceCategory::TYPES),
            'image_path' => null,
            'status' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function type(string $type): static
    {
        return $this->state(fn () => ['category_type' => $type]);
    }

    public function male(): static
    {
        return $this->state(fn () => ['gender' => ServiceCategory::GENDER_MALE]);
    }

    public function female(): static
    {
        return $this->state(fn () => ['gender' => ServiceCategory::GENDER_FEMALE]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
