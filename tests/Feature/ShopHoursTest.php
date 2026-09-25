<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

class ShopHoursTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function setHours(string $open, string $close): void
    {
        SiteSetting::query()->updateOrCreate(['key' => 'shop_opens_at'], ['value' => $open, 'type' => 'time', 'group' => 'shop_hours']);
        SiteSetting::query()->updateOrCreate(['key' => 'shop_closes_at'], ['value' => $close, 'type' => 'time', 'group' => 'shop_hours']);
    }

    private function activeService(int $minutes = 60): Service
    {
        $category = ServiceCategory::factory()->female()->create();
        $service = Service::factory()->forCategory($category)->create(['duration_minutes' => $minutes]);

        $this->bookableStylistFor($service);

        return $service;
    }

    private function bookingPayload(Service $service, string $time): array
    {
        return [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'appointment_date' => now()->addDays(2)->toDateString(),
            'appointment_time' => $time,
        ];
    }

    public function test_migration_seeds_default_shop_hours(): void
    {
        $this->assertSame('10:00', SiteSetting::where('key', 'shop_opens_at')->value('value'));
        $this->assertSame('19:30', SiteSetting::where('key', 'shop_closes_at')->value('value'));
    }

    public function test_public_site_settings_expose_shop_hours(): void
    {
        $this->setHours('09:30', '18:00');

        $this->getJson('/api/site-settings')
            ->assertOk()
            ->assertJsonPath('data.shop_opens_at', '09:30')
            ->assertJsonPath('data.shop_closes_at', '18:00');
    }

    public function test_admin_can_update_and_persist_shop_hours(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->putJson('/api/admin/site-settings', [
            'settings' => ['shop_opens_at' => '11:00', 'shop_closes_at' => '20:00'],
        ])->assertOk();

        // survives a fresh read
        Cache::flush();
        $this->getJson('/api/admin/site-settings')
            ->assertOk()
            ->assertJsonFragment(['key' => 'shop_opens_at', 'value' => '11:00'])
            ->assertJsonFragment(['key' => 'shop_closes_at', 'value' => '20:00']);
    }

    public function test_admin_update_rejects_close_before_or_equal_open(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->putJson('/api/admin/site-settings', [
            'settings' => ['shop_opens_at' => '18:00', 'shop_closes_at' => '18:00'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.shop_closes_at');

        $this->putJson('/api/admin/site-settings', [
            'settings' => ['shop_opens_at' => '18:00', 'shop_closes_at' => '17:00'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.shop_closes_at');
    }

    public function test_admin_update_rejects_malformed_time(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->putJson('/api/admin/site-settings', [
            'settings' => ['shop_opens_at' => '9am', 'shop_closes_at' => '19:30'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.shop_opens_at');
    }

    public function test_booking_is_rejected_outside_saved_shop_hours(): void
    {
        $this->actingAsToken($this->customer());
        $this->setHours('10:00', '19:30');
        $service = $this->activeService();

        $this->postJson('/api/appointments', $this->bookingPayload($service, '09:30'))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        $this->postJson('/api/appointments', $this->bookingPayload($service, '19:30'))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');
    }

    public function test_booking_respects_a_changed_shop_hours_window(): void
    {
        $this->actingAsToken($this->customer());
        $service = $this->activeService();

        // Default window rejects 09:00...
        $this->setHours('10:00', '19:30');
        $this->postJson('/api/appointments', $this->bookingPayload($service, '09:00'))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_time');

        // ...widen the window and the same time is accepted.
        $this->setHours('08:00', '21:00');
        $this->postJson('/api/appointments', $this->bookingPayload($service, '09:00'))
            ->assertCreated();
    }

    public function test_booking_within_saved_hours_still_succeeds(): void
    {
        $this->actingAsToken($this->customer());
        $this->setHours('10:00', '19:30');
        $service = $this->activeService();

        $this->postJson('/api/appointments', $this->bookingPayload($service, '14:00'))
            ->assertCreated();
    }
}
