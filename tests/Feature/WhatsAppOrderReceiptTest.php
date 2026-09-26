<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\Order;
use App\Models\Product;
use App\Support\OrderBillPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * A shop order (product detail → Buy now / cart → Razorpay) whose payment was
 * just verified → the customer gets a WhatsApp message with the bill PDF.
 * Razorpay and Meta are both faked — no test ever calls either for real.
 */
class WhatsAppOrderReceiptTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    // What the faked Meta answers with. A test changes these BEFORE acting; calling
    // Http::fake() a second time would not override the first (the first match wins).
    private int $uploadStatus = 200;

    private array $uploadReply = ['id' => 'MEDIA123'];

    private int $messageStatus = 200;

    private array $messageReply = ['messages' => [['id' => 'wamid.TEST']]];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1347967731728408',
            'services.whatsapp.language' => 'en_US',
            'services.whatsapp.template_order' => 'order_paid',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'api.razorpay.com')) {
                return Http::response([
                    'id' => 'order_'.$request->data()['receipt'],
                    'entity' => 'order',
                    'amount' => $request->data()['amount'],
                    'currency' => 'INR',
                    'status' => 'created',
                ], 200);
            }

            return str_ends_with($request->url(), '/media')
                ? Http::response($this->uploadReply, $this->uploadStatus)
                : Http::response($this->messageReply, $this->messageStatus);
        });

        $this->actingAsToken($this->customer());
    }

    private function product(string $name, int $price): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'mrp' => $price + 100,
            'selling_price' => $price,
            'tax_percent' => 0,
            'stock_quantity' => 100,
        ]);
    }

    private function line(Product $product, int $quantity = 1): array
    {
        return ['type' => 'product', 'product_id' => $product->id, 'quantity' => $quantity];
    }

    private function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, config('services.razorpay.secret'));
    }

    /** Opens a Razorpay checkout for the basket. */
    private function checkout(array $items, string $phone = '9876543210'): TestResponse
    {
        return $this->postJson('/api/checkout', ['customer_name' => 'Asha Rao', 'phone' => $phone, 'items' => $items])->assertCreated();
    }

    private function verify(TestResponse $checkout, string $paymentId = 'pay_ok', ?string $signature = null): TestResponse
    {
        $orderId = $checkout->json('data.order_id');

        return $this->postJson('/api/checkout/'.$checkout->json('data.checkout_id').'/verify', [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature ?? $this->signature($orderId, $paymentId),
        ]);
    }

    /** Checkout + a correctly signed payment, i.e. a customer who really paid. */
    private function pay(array $items, string $phone = '9876543210'): TestResponse
    {
        return $this->verify($this->checkout($items, $phone));
    }

    /** @return array<int, Request> */
    private function requestsEndingIn(string $suffix): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request) => str_ends_with($request->url(), $suffix))
            ->values()
            ->all();
    }

    /** @return array<int, Request> the PDF uploads sent to Meta */
    private function uploads(): array
    {
        return $this->requestsEndingIn('/1347967731728408/media');
    }

    /** @return array<int, Request> the template messages sent to Meta */
    private function messages(): array
    {
        return $this->requestsEndingIn('/1347967731728408/messages');
    }

    private function bodyOf(Request $message): array
    {
        return collect($message['template']['components'])->firstWhere('type', 'body')['parameters'];
    }

    // --- The message ----------------------------------------------------------

    public function test_a_paid_order_sends_the_order_paid_template_with_the_bill_pdf(): void
    {
        $oil = $this->product('Argan Oil', 800);
        $serum = $this->product('Face Serum', 500);

        $this->pay([$this->line($oil, 2), $this->line($serum)])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertCount(1, $this->uploads());
        $this->assertCount(1, $this->messages());

        $message = $this->messages()[0];
        [$header, $body] = $message['template']['components'];

        $this->assertSame('919876543210', $message['to']);
        $this->assertSame('order_paid', $message['template']['name']);
        $this->assertSame('en_US', $message['template']['language']['code']);

        // the bill goes in the template's Document header…
        $this->assertSame('header', $header['type']);
        $this->assertSame(
            [['type' => 'document', 'document' => ['id' => 'MEDIA123', 'filename' => "bill-{$order->order_number}.pdf"]]],
            $header['parameters'],
        );

        // …and the body is {{1}} name, {{2}} order number, {{3}} items, {{4}} amount paid
        $this->assertSame('body', $body['type']);
        $this->assertSame(
            ['Asha Rao', $order->order_number, 'Argan Oil x2, Face Serum x1', '2,100.00'],
            collect($body['parameters'])->pluck('text')->all(),
        );
    }

    public function test_the_uploaded_file_is_a_real_pdf_named_after_the_order(): void
    {
        $this->pay([$this->line($this->product('Argan Oil', 800))])->assertCreated();

        $order = Order::firstOrFail();
        $upload = $this->uploads()[0];

        $this->assertTrue($upload->isMultipart());
        $this->assertTrue($upload->hasFile('file', null, "bill-{$order->order_number}.pdf"));
        $this->assertSame('application/pdf', collect($upload->data())->firstWhere('name', 'type')['contents']);
        $this->assertStringStartsWith('%PDF', collect($upload->data())->firstWhere('name', 'file')['contents']);
    }

    public function test_a_long_basket_is_summarised_on_one_line(): void
    {
        $lines = collect(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'])
            ->map(fn (string $name) => $this->line($this->product($name, 300)))
            ->all();

        $this->pay($lines)->assertCreated();

        $this->assertSame(
            'Alpha x1, Bravo x1, Charlie x1 +2 more',
            $this->bodyOf($this->messages()[0])[2]['text'],
        );
    }

    public function test_a_combo_order_is_sent_and_named_in_the_message(): void
    {
        $one = $this->product('Pro-1', 800);
        $two = $this->product('Pro-2', 850);
        $combo = Combo::create(['name' => 'Hair Care Package', 'slug' => 'hair-care-package', 'bundle_price' => 1000]);
        foreach ([[$one, 500], [$two, 600]] as $i => [$product, $price]) {
            $combo->items()->create(['product_id' => $product->id, 'price' => $price, 'sort_order' => $i]);
        }

        $this->pay([['type' => 'combo', 'combo_id' => $combo->id, 'product_ids' => [$one->id, $two->id], 'quantity' => 1]])
            ->assertCreated();

        $this->assertCount(1, $this->messages());
        $this->assertSame('Hair Care Package x1', $this->bodyOf($this->messages()[0])[2]['text']);
    }

    // --- Only a genuinely new, paid order sends -------------------------------

    public function test_a_replayed_payment_callback_sends_no_second_message(): void
    {
        $checkout = $this->checkout([$this->line($this->product('Argan Oil', 800))]);

        $this->verify($checkout)->assertCreated();
        $this->verify($checkout)->assertOk(); // same payment, replayed → the existing order

        $this->assertSame(1, Order::count());
        $this->assertCount(1, $this->uploads());
        $this->assertCount(1, $this->messages());
    }

    public function test_a_forged_payment_creates_no_order_and_sends_nothing(): void
    {
        $checkout = $this->checkout([$this->line($this->product('Argan Oil', 800))]);

        $this->verify($checkout, 'pay_forged', 'not-a-valid-signature')->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertCount(0, $this->uploads());
        $this->assertCount(0, $this->messages());
    }

    public function test_opening_a_checkout_without_paying_sends_nothing(): void
    {
        $this->checkout([$this->line($this->product('Argan Oil', 800))]);

        $this->assertCount(0, $this->uploads());
        $this->assertCount(0, $this->messages());
    }

    // --- Failures never break the order ---------------------------------------

    public function test_when_the_pdf_upload_fails_no_message_is_sent_and_the_order_is_still_placed(): void
    {
        $this->uploadStatus = 400;
        $this->uploadReply = ['error' => ['message' => 'Upload failed', 'code' => 100]];

        $this->pay([$this->line($this->product('Argan Oil', 800))])->assertCreated();

        $this->assertSame(1, Order::count());
        $this->assertCount(1, $this->uploads());
        $this->assertCount(0, $this->messages()); // a Document-header template can't go out without its document
    }

    public function test_a_whatsapp_failure_never_fails_or_undoes_the_payment(): void
    {
        $this->messageStatus = 400;
        $this->messageReply = ['error' => ['message' => 'Authorization Error', 'code' => 100]];

        $this->pay([$this->line($this->product('Argan Oil', 800))])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertCount(1, $this->messages()); // Meta really was asked — and refused
        $this->assertSame(Order::PAYMENT_PAID, Order::firstOrFail()->payment_status);
    }

    public function test_nothing_is_sent_while_whatsapp_is_not_configured(): void
    {
        config(['services.whatsapp.token' => '']);

        $this->pay([$this->line($this->product('Argan Oil', 800))])->assertCreated();

        $this->assertSame(1, Order::count());
        $this->assertCount(0, $this->uploads());
        $this->assertCount(0, $this->messages());
    }

    public function test_an_unusable_phone_number_is_skipped_without_error(): void
    {
        $this->pay([$this->line($this->product('Argan Oil', 800))], '12345678')->assertCreated();

        $this->assertSame(1, Order::count());
        $this->assertCount(0, $this->uploads()); // not even the PDF upload
        $this->assertCount(0, $this->messages());
    }

    public function test_a_country_coded_number_is_sent_as_is(): void
    {
        $this->pay([$this->line($this->product('Argan Oil', 800))], '+91 98765 43210')->assertCreated();

        $this->assertSame('919876543210', $this->messages()[0]['to']);
    }

    // --- The bill itself ------------------------------------------------------

    public function test_the_bill_lists_every_line_and_the_amount_paid(): void
    {
        $oil = $this->product('Argan Oil', 800);
        $one = $this->product('Pro-1', 800);
        $two = $this->product('Pro-2', 850);
        $combo = Combo::create(['name' => 'Hair Care Package', 'slug' => 'hair-care-package', 'bundle_price' => 1000]);
        foreach ([[$one, 500], [$two, 600]] as $i => [$product, $price]) {
            $combo->items()->create(['product_id' => $product->id, 'price' => $price, 'sort_order' => $i]);
        }

        $this->pay([
            $this->line($oil, 2),
            ['type' => 'combo', 'combo_id' => $combo->id, 'product_ids' => [$one->id, $two->id], 'quantity' => 1],
        ])->assertCreated();

        $order = Order::firstOrFail();
        $html = view('pdf.order-bill', [
            'order' => $order->load('items.selectedProducts'),
            'salonName' => 'DK StyleHub', 'salonPhone' => '+91 70103 41213', 'salonAddress' => null,
        ])->render();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Asha Rao', $html);
        $this->assertStringContainsString('9876543210', $html);
        $this->assertStringContainsString('Argan Oil', $html);
        $this->assertStringContainsString('Hair Care Package', $html);
        $this->assertStringContainsString('Pro-1, Pro-2', $html); // the products chosen inside the combo
        $this->assertStringContainsString('PAID', $html);
        $this->assertStringContainsString('pay_ok', $html);
        $this->assertStringContainsString('&#8377; '.number_format((float) $order->amount_paid, 2), $html);
        $this->assertStringContainsString('include applicable taxes', $html);
    }

    public function test_the_bill_keeps_the_price_paid_even_after_the_product_price_changes(): void
    {
        $oil = $this->product('Argan Oil', 800);
        $this->pay([$this->line($oil)])->assertCreated();

        $oil->update(['selling_price' => 999]);

        $html = view('pdf.order-bill', [
            'order' => Order::firstOrFail()->load('items.selectedProducts'),
            'salonName' => 'DK StyleHub', 'salonPhone' => null, 'salonAddress' => null,
        ])->render();

        $this->assertStringContainsString('&#8377; 800.00', $html);
        $this->assertStringNotContainsString('999.00', $html);
    }

    public function test_the_bill_renders_as_a_pdf(): void
    {
        $this->pay([$this->line($this->product('Argan Oil', 800), 2)])->assertCreated();

        $order = Order::firstOrFail();

        $this->assertSame("bill-{$order->order_number}.pdf", OrderBillPdf::filename($order));
        $this->assertStringStartsWith('%PDF', OrderBillPdf::make($order)->output());
    }
}
