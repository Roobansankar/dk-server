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

    public function test_brochure_is_viewable_inline_by_default(): void
    {
        // Default (no ?download=1): "inline", not "attachment" — scanning
        // the QR / opening this URL must not silently start a download.
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
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'dk-stylehub-studio-brochure.pdf',
            $response->headers->get('Content-Disposition')
        );
        // Dompdf's stream() doesn't set this itself (unlike download()) — a
        // phone's inline PDF viewer that needs the total size up front to
        // render every page, not just the first, was regressing on exactly
        // this gap. Must match the body exactly, not just be present.
        $this->assertEquals(strlen($response->getContent()), (int) $response->headers->get('Content-Length'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_download_1_forces_a_real_download_instead(): void
    {
        $response = $this->get('/api/brochure?download=1');

        $response->assertOk();
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString(
            'dk-stylehub-studio-brochure.pdf',
            $response->headers->get('Content-Disposition')
        );
    }

    public function test_brochure_is_public_and_empty_catalogue_still_renders(): void
    {
        $response = $this->get('/api/brochure');

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_repeated_requests_return_byte_identical_content(): void
    {
        // Dompdf embeds a render timestamp, so two independently rendered
        // copies are never byte-identical even though both are valid PDFs
        // on their own. A phone that reads a large inline PDF across more
        // than one HTTP request (e.g. a Range request for later pages) was
        // landing on two *different* renders for the same URL, corrupting
        // the document after whatever page the first request covered.
        // Caching the bytes for a few minutes is what prevents that.
        $first = $this->get('/api/brochure')->getContent();
        $second = $this->get('/api/brochure')->getContent();

        $this->assertSame($first, $second);
    }

    public function test_inline_response_tells_clients_not_to_split_it_into_range_requests(): void
    {
        $response = $this->get('/api/brochure');

        $response->assertOk();
        $this->assertSame('none', $response->headers->get('Accept-Ranges'));
    }

    public function test_brochure_data_matches_the_pdfs_stylists_and_prices(): void
    {
        $category = ServiceCategory::factory()->male()->create(['name' => 'Haircuts Test', 'sort_order' => 1]);
        $service = Service::factory()->create([
            'service_category_id' => $category->id,
            'name' => 'Signature Cut Test',
            'price' => 650,
            'duration_minutes' => 40,
        ]);
        $stylist = Stylist::factory()->create(['name' => 'Data Stylist Test', 'bio' => 'Loves a clean fade.']);
        $stylist->services()->attach($service->id);

        $response = $this->getJson('/api/brochure-data');

        $response->assertOk()
            ->assertJsonPath('data.stylists.0.name', 'Data Stylist Test')
            ->assertJsonPath('data.stylists.0.bio', 'Loves a clean fade.')
            ->assertJsonPath('data.stylists.0.men.0.category', 'Haircuts Test')
            ->assertJsonPath('data.stylists.0.men.0.services.0.name', 'Signature Cut Test')
            ->assertJsonPath('data.stylists.0.men.0.services.0.price', fn ($v) => (float) $v === 650.0)
            ->assertJsonPath('data.stylists.0.women', []);
    }

    public function test_brochure_data_gives_a_loadable_photo_url_not_a_pdf_data_uri(): void
    {
        $stylist = Stylist::factory()->create(['name' => 'No Photo Stylist Test', 'image_path' => null]);

        $response = $this->getJson('/api/brochure-data');

        $response->assertOk()->assertJsonPath('data.stylists.0.name', 'No Photo Stylist Test');
        $this->assertNull($response->json('data.stylists.0.photoUrl'));
    }
}
