<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\BillPdf;
use App\Support\OrderBillPdf;
use App\Support\PdfLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The look of the two bill PDFs (the appointment bill staff download and WhatsApp
 * sends, and the shop-order bill): the real DK StyleHub logo on top, the slot as
 * "start – end", the balance when something is still owed. Built from unsaved
 * models — no bill here needs a database row.
 */
class BillPdfDesignTest extends TestCase
{
    use RefreshDatabase;

    private function appointment(array $attributes = []): Appointment
    {
        return (new Appointment)->forceFill($attributes + [
            'customer_name' => 'Rooban Sankar',
            'phone' => '9876543210',
            'reference' => 'APT-MKVQE1ZK',
            'service_name' => "Men's Hair Styling",
            'category_name' => 'Haircuts and Styling',
            'stylist_name' => 'Karan Rao',
            'service_price' => 1500,
            'advance_amount' => 300,
            'duration_minutes' => 60,
            'payment_status' => 'paid',
            'appointment_date' => '2026-09-26',
            'appointment_time' => '20:00:00',
        ]);
    }

    private function order(): Order
    {
        $order = (new Order)->forceFill([
            'order_number' => 'ORD-QZIQX8QB',
            'customer_name' => 'Asha Rao',
            'phone' => '9876543210',
            'total' => 1798,
            'amount_paid' => 1798,
            'payment_status' => 'paid',
            'paid_at' => Carbon::parse('2026-09-26 14:42:00', 'UTC'),
        ]);
        $item = (new OrderItem)->forceFill(['item_type' => 'product', 'name' => 'Argan Oil', 'unit_price' => 899, 'quantity' => 2, 'line_total' => 1798]);
        $item->setRelation('selectedProducts', collect());
        $order->setRelation('items', collect([$item]));

        return $order;
    }

    private function billHtml(Appointment $appointment): string
    {
        return view('pdf.bill', [
            'appointment' => $appointment,
            'salonName' => 'DK StyleHub',
            'salonPhone' => '+91 97904 31212',
            'salonAddress' => null,
            'generatedAt' => Carbon::parse('2026-09-26 20:15:00', 'Asia/Kolkata'),
        ])->render();
    }

    private function setLogoCache(?string $value): void
    {
        (new ReflectionProperty(PdfLogo::class, 'uri'))->setValue(null, $value);
    }

    protected function tearDown(): void
    {
        $this->setLogoCache(null);

        parent::tearDown();
    }

    // --- The logo ----------------------------------------------------------------

    public function test_the_logo_is_the_dark_dk_stylehub_mark_as_a_transparent_png(): void
    {
        $uri = PdfLogo::dataUri();

        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        $bytes = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($bytes);

        [$width, $height] = getimagesizefromstring($bytes);
        $this->assertGreaterThanOrEqual(600, $width, 'sharp enough to print');
        $this->assertGreaterThan($height, $width, 'the wide DK / STYLEHUB lock-up');

        $image = imagecreatefromstring($bytes);
        $this->assertSame(127, (imagecolorat($image, 2, 2) >> 24) & 127, 'the corners are transparent');

        $dark = 0;
        for ($y = 0; $y < $height; $y += 6) {
            for ($x = 0; $x < $width; $x += 6) {
                $c = imagecolorat($image, $x, $y);
                if ((($c >> 24) & 127) < 30 && (($c >> 16) & 255) < 60) {
                    $dark++;
                }
            }
        }
        $this->assertGreaterThan(500, $dark, 'the artwork is dark ink, for light backgrounds');
    }

    // --- The appointment bill ------------------------------------------------------

    public function test_the_appointment_bill_carries_the_logo_and_says_paid_in_full(): void
    {
        $html = $this->billHtml($this->appointment());

        $this->assertStringContainsString('<img class="logo" src="data:image/png;base64,', $html);
        $this->assertStringContainsString('PAID IN FULL', $html);
        $this->assertStringContainsString('APT-MKVQE1ZK', $html);
        $this->assertStringContainsString('&#8377; 1,500.00', $html);
        $this->assertStringNotContainsString('Balance due', $html);
    }

    public function test_the_bill_shows_when_the_slot_starts_and_ends(): void
    {
        $this->assertStringContainsString('08:00 PM – 09:00 PM', $this->billHtml($this->appointment()));
        $this->assertStringContainsString('11:30 AM – 01:00 PM', $this->billHtml($this->appointment(['appointment_time' => '11:30:00', 'duration_minutes' => 90])));
    }

    public function test_the_bill_shows_only_the_start_when_the_length_is_unknown(): void
    {
        $html = $this->billHtml($this->appointment(['duration_minutes' => null]));

        $this->assertStringContainsString('08:00 PM', $html);
        $this->assertStringNotContainsString('08:00 PM –', $html);
    }

    public function test_an_advance_paid_bill_shows_what_was_received_and_the_balance_due(): void
    {
        $html = $this->billHtml($this->appointment(['payment_status' => 'advance_paid']));

        $this->assertStringContainsString('ADVANCE PAID', $html);
        $this->assertStringNotContainsString('PAID IN FULL', $html);
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('&#8377; 1,200.00', $html); // ₹1,500 − the ₹300 advance
        $this->assertStringContainsString('&#8377; 300.00', $html);   // received so far
    }

    public function test_the_bill_date_is_the_studios_time_not_the_servers_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-26 11:32:00', 'UTC')); // 05:02 PM in India

        try {
            $html = BillPdf::make($this->appointment())->getDomPDF()->outputHtml();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString('26 Sep 2026, 05:02 PM', $html);
        $this->assertStringContainsString('generated 26 Sep 2026 17:02', $html);
    }

    public function test_a_bill_without_the_logo_file_falls_back_to_the_salon_name(): void
    {
        $this->setLogoCache('');

        $html = $this->billHtml($this->appointment());

        $this->assertStringNotContainsString('<img class="logo"', $html);
        $this->assertStringContainsString('DK StyleHub', $html);
        $this->assertStringContainsString('PAID IN FULL', $html);
    }

    // --- The order bill ------------------------------------------------------------

    public function test_the_order_bill_carries_the_logo_too(): void
    {
        $html = view('pdf.order-bill', [
            'order' => $this->order(),
            'salonName' => 'DK StyleHub',
            'salonPhone' => null,
            'salonAddress' => null,
        ])->render();

        $this->assertStringContainsString('<img class="logo" src="data:image/png;base64,', $html);
        $this->assertStringContainsString('ORD-QZIQX8QB', $html);
        $this->assertStringContainsString('Argan Oil', $html);
        $this->assertStringContainsString('include applicable taxes', $html);
    }

    // --- The real PDFs --------------------------------------------------------------

    public function test_both_pdfs_render_with_the_logo_embedded_as_an_image(): void
    {
        $appointmentPdf = BillPdf::make($this->appointment())->output();
        $orderPdf = OrderBillPdf::make($this->order())->output();

        foreach ([$appointmentPdf, $orderPdf] as $pdf) {
            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertStringContainsString('/Subtype /Image', $pdf, 'the logo is embedded in the file');
        }
    }
}
