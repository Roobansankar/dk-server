<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Stylist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $price = fake()->randomElement([600, 900, 1200, 1600, 3200]);
        $pct = fake()->randomElement([0, 10, 25, 50]);

        return [
            'reference' => Appointment::generateReference(),
            'customer_name' => fake()->name(),
            'phone' => fake()->numerify('+91 9#########'),
            'gender' => fake()->randomElement(['male', 'female', 'unisex']),
            'source' => Appointment::SOURCE_ONLINE,
            'service_category_id' => null,
            'service_id' => null,
            'stylist_id' => null,
            'category_name' => fake()->randomElement(['Hair', 'Skin', 'Colour', 'Massage']),
            'service_name' => fake()->randomElement(['Haircut', 'Facial', 'Balayage', 'Head Massage']),
            'stylist_name' => null,
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90]),
            'service_price' => $price,
            'advance_percentage' => $pct,
            'advance_amount' => round($price * $pct / 100, 2),
            'appointment_date' => fake()->dateTimeBetween('-20 days', '+20 days')->format('Y-m-d'),
            'appointment_time' => fake()->randomElement(['10:00', '11:30', '14:00', '16:30', '18:00']),
            'message' => fake()->optional()->sentence(),
            'notes' => null,
            'status' => fake()->randomElement(Appointment::STATUSES),
            'payment_status' => Appointment::PAYMENT_UNPAID,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => Appointment::STATUS_PENDING]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => Appointment::STATUS_COMPLETED,
            'payment_status' => ($attrs['advance_percentage'] ?? 0) > 0
                ? fake()->randomElement([Appointment::PAYMENT_ADVANCE_PAID, Appointment::PAYMENT_PAID])
                : fake()->randomElement([Appointment::PAYMENT_UNPAID, Appointment::PAYMENT_PAID]),
            'appointment_date' => fake()->dateTimeBetween('-40 days', '-1 days')->format('Y-m-d'),
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['source' => Appointment::SOURCE_OFFLINE]);
    }

    public function forStylist(Stylist $stylist): static
    {
        return $this->state(fn () => [
            'stylist_id' => $stylist->id,
            'stylist_name' => $stylist->name,
        ]);
    }

    public function forService(Service $service): static
    {
        return $this->state(fn () => [
            'service_id' => $service->id,
            'service_category_id' => $service->service_category_id,
            'category_name' => $service->category?->name,
            'service_name' => $service->name,
            'duration_minutes' => $service->duration_minutes,
            'service_price' => $service->price,
            'advance_percentage' => $service->advance_percentage,
            'advance_amount' => $service->advance_amount,
        ]);
    }
}
