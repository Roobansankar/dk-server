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
}
