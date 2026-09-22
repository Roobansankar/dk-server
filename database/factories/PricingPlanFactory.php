<?php

namespace Database\Factories;

use App\Models\PricingPlan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PricingPlan>
 */
class PricingPlanFactory extends Factory
{
    protected $model = PricingPlan::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Essential', 'Signature', 'Premium', 'Bridal', 'Monthly Care', 'Student',
        ]).' Package';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomElement([1999, 2999, 4999, 7999, 11999]),
            'validity_days' => fake()->randomElement([null, 30, 90, 180, 365]),
            'features' => fake()->randomElements([
                'Haircut & styling', 'Hair spa', 'Express facial', 'Head massage',
                'Priority booking', 'Complimentary consultation',
            ], fake()->numberBetween(2, 4)),
            'status' => true,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }
}
