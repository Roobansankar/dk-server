<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stylist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public studio brochure behind the QR code on /contact: one PDF with
 * every active stylist (photo or initial placeholder) plus the price list.
 */
class StudioBrochureTest extends TestCase
{
    use RefreshDatabase;

    public function test_brochure_returns_a_pdf_download(): void
    {
        $category = ServiceCategory::factory()->female()->create(['name' => 'Haircuts Test']);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'name' => 'Signature Cut Test',
            'price' => 600,
            'duration_minutes' => 40,
        ]);
        $stylist = Stylist::factory()->create(['name' => 'Brochure Stylist Test']);
        $stylist->services()->attach($service->id);

        $response = $this->get('/api/brochure');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'dk-stylehub-studio-brochure.pdf',
            $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_brochure_is_public_and_empty_catalogue_still_renders(): void
    {
        $response = $this->get('/api/brochure');

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
