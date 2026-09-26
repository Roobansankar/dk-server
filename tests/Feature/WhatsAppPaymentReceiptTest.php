<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Stylist;
use App\Support\BillPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

/**
 * Staff mark an appointment "Paid in full" (the customer paid an advance
 * online and settled the rest at the studio) → one WhatsApp payment receipt
 * with the bill PDF attached. Meta's API is always faked here — no test ever
 * sends a real message.
 */
class WhatsAppPaymentReceiptTest extends TestCase
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
        Cache::flush();

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.token' => 'test-token',
            'services.whatsapp.phone_number_id' => '1347967731728408',
            'services.whatsapp.language' => 'en_US',
            'services.whatsapp.template' => 'booking_confirmed',
            'services.whatsapp.template_paid' => 'payment_received',
        ]);
        $this->fakeMeta();

        $this->actingAsToken($this->superadmin());
    }

    /** By default Meta accepts the PDF upload (returning a media id) and the message. */
    private function fakeMeta(): void
    {
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/media')
            ? Http::response($this->uploadReply, $this->uploadStatus)
            : Http::response($this->messageReply, $this->messageStatus));
    }

    /** The named part of a multipart request (the PDF upload). */
    private function part(Request $request, string $name): array
    {
        return collect($request->data())->firstWhere('name', $name) ?? [];
    }

    private function service(): Service
    {
        return Service::factory()
            ->forCategory(ServiceCategory::factory()->female()->create())
            ->create(['name' => 'Signature Facial', 'price' => 1500, 'advance_percentage' => 20, 'duration_minutes' => 60]);
    }

    /** A confirmed booking whose 20% advance (₹300) was paid online. */
    private function advancePaid(array $overrides = []): Appointment
    {
        $service = $this->service();

        return Appointment::factory()->forService($service)->forStylist(Stylist::factory()->create())->create($overrides + [
            'customer_name' => 'Priya R',
            'phone' => '9944381709',
            'status' => Appointment::STATUS_CONFIRMED,
            'payment_status' => Appointment::PAYMENT_ADVANCE_PAID,
            'appointment_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
            'appointment_time' => '11:00',
        ]);
    }

    private function setPayment(Appointment $appointment, string $status)
    {
        return $this->patchJson("/api/admin/appointments/{$appointment->id}", ['payment_status' => $status]);
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

    // --- The message ----------------------------------------------------------

    public function test_marking_paid_in_full_sends_the_payment_received_template_with_the_bill_pdf(): void
    {
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk()->assertJsonPath('data.payment_status', 'paid');

        $this->assertCount(1, $this->uploads());
        $this->assertCount(1, $this->messages());

        $message = $this->messages()[0];
        [$header, $body] = $message['template']['components'];

        $this->assertSame('919944381709', $message['to']);
        $this->assertSame('template', $message['type']);
        $this->assertSame('payment_received', $message['template']['name']);
        $this->assertSame('en_US', $message['template']['language']['code']);

        // the PDF goes in the template's Document header, by the media id Meta returned…
        $this->assertSame('header', $header['type']);
        $this->assertSame(
            [['type' => 'document', 'document' => ['id' => 'MEDIA123', 'filename' => "bill-{$appointment->reference}.pdf"]]],
            $header['parameters'],
        );

        // …and the body is {{1}} name, {{2}} service, {{3}} the FULL bill (not the advance), {{4}} reference
        $this->assertSame('body', $body['type']);
        $this->assertSame(
            ['Priya R', 'Signature Facial', '1,500.00', $appointment->reference],
            collect($body['parameters'])->pluck('text')->all(),
        );
    }

    public function test_the_uploaded_file_is_a_real_pdf_named_after_the_booking(): void
    {
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk();

        $upload = $this->uploads()[0];

        $this->assertTrue($upload->isMultipart());
        $this->assertTrue($upload->hasFile('file', null, "bill-{$appointment->reference}.pdf"));
        $this->assertSame('whatsapp', $this->part($upload, 'messaging_product')['contents']);
        $this->assertSame('application/pdf', $this->part($upload, 'type')['contents']);
        $this->assertStringStartsWith('%PDF', $this->part($upload, 'file')['contents']);
        $this->assertSame('Bearer test-token', $upload->header('Authorization')[0]);
    }

    public function test_the_pdf_is_uploaded_before_the_message_and_the_bill_is_sent_only_to_that_customer(): void
    {
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk();

        $order = Http::recorded()->map(fn (array $pair) => basename($pair[0]->url()))->values()->all();
        $this->assertSame(['media', 'messages'], $order);
    }

    public function test_the_bill_is_the_same_document_staff_can_download(): void
    {
        $appointment = $this->advancePaid(['payment_status' => Appointment::PAYMENT_PAID]);

        $download = $this->get("/api/admin/appointments/{$appointment->id}/bill");

        $download->assertOk();
        $this->assertStringContainsString("bill-{$appointment->reference}.pdf", $download->headers->get('Content-Disposition'));
        $this->assertSame(BillPdf::filename($appointment), "bill-{$appointment->reference}.pdf");
        $this->assertStringStartsWith('%PDF', $download->getContent());
    }

    // --- When it is (not) sent ------------------------------------------------

    public function test_other_payment_changes_send_nothing(): void
    {
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'unpaid')->assertOk();
        $this->setPayment($appointment, 'advance_paid')->assertOk();

        Http::assertNothingSent();
    }

    public function test_saving_paid_again_or_editing_something_else_does_not_send_a_second_receipt(): void
    {
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk();
        $this->setPayment($appointment, 'paid')->assertOk();
        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['notes' => 'Regular client'])->assertOk();

        $this->assertCount(1, $this->uploads());
        $this->assertCount(1, $this->messages());
    }

    public function test_an_appointment_that_was_already_paid_sends_nothing_when_edited(): void
    {
        $appointment = $this->advancePaid(['payment_status' => Appointment::PAYMENT_PAID]);

        $this->setPayment($appointment, 'paid')->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_cancelled_or_rejected_appointment_gets_no_receipt(): void
    {
        foreach ([Appointment::STATUS_CANCELLED, Appointment::STATUS_REJECTED] as $status) {
            $appointment = $this->advancePaid(['status' => $status]);

            $this->setPayment($appointment, 'paid')->assertOk();
        }

        Http::assertNothingSent();
    }

    public function test_confirming_and_marking_paid_in_one_edit_sends_a_single_receipt(): void
    {
        $appointment = $this->advancePaid(['status' => Appointment::STATUS_PENDING, 'payment_status' => Appointment::PAYMENT_UNPAID]);

        // status → confirmed takes the slot-locking path in the controller
        $this->patchJson("/api/admin/appointments/{$appointment->id}", ['status' => 'confirmed', 'payment_status' => 'paid'])
            ->assertOk();

        $this->assertCount(1, $this->messages());
        $this->assertSame('payment_received', $this->messages()[0]['template']['name']);
    }

    // --- Failures never break the payment update --------------------------------

    public function test_when_the_pdf_upload_fails_no_message_is_sent_and_the_payment_still_saves(): void
    {
        $this->uploadStatus = 400;
        $this->uploadReply = ['error' => ['message' => 'Upload failed', 'code' => 100]];
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk();

        // a template with a Document header can't go out without its document
        $this->assertCount(1, $this->uploads());
        $this->assertCount(0, $this->messages());
        $this->assertSame(Appointment::PAYMENT_PAID, $appointment->refresh()->payment_status);
    }

    public function test_an_upload_reply_without_a_media_id_is_treated_as_a_failure(): void
    {
        $this->uploadReply = []; // 200, but no media id
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk();

        $this->assertCount(1, $this->uploads());
        $this->assertCount(0, $this->messages());
    }

    public function test_a_whatsapp_failure_never_undoes_or_fails_the_payment_update(): void
    {
        $this->messageStatus = 400;
        $this->messageReply = ['error' => ['message' => 'Authorization Error', 'code' => 100]];
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk();

        // Meta really was asked (and refused) — the payment is saved regardless
        $this->assertCount(1, $this->messages());
        $this->assertSame(Appointment::PAYMENT_PAID, $appointment->refresh()->payment_status);
    }

    public function test_nothing_is_sent_while_whatsapp_is_not_configured(): void
    {
        config(['services.whatsapp.token' => '']);
        $appointment = $this->advancePaid();

        $this->setPayment($appointment, 'paid')->assertOk();

        Http::assertNothingSent();
        $this->assertSame(Appointment::PAYMENT_PAID, $appointment->refresh()->payment_status);
    }

    public function test_an_unusable_phone_number_is_skipped_without_error(): void
    {
        $appointment = $this->advancePaid(['phone' => '12345']);

        $this->setPayment($appointment, 'paid')->assertOk();

        Http::assertNothingSent(); // not even the PDF upload
    }

    public function test_a_local_number_is_sent_with_the_india_country_code(): void
    {
        $appointment = $this->advancePaid(['phone' => '+91 99443 81709']);

        $this->setPayment($appointment, 'paid')->assertOk();

        $this->assertSame('919944381709', $this->messages()[0]['to']);
    }

    // --- Created through the Offline Appointment form -----------------------------
    // "Advance paid" → booking_confirmed;  "Paid in full" → the receipt with the bill PDF.

    /** The payload the Offline Appointment form sends. */
    private function offline(array $overrides = []): array
    {
        $service = $this->service();

        return array_merge([
            'customer_name' => 'Walk-in Guest',
            'phone' => '9944381709',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => Stylist::factory()->create()->id,
            'appointment_date' => now('Asia/Kolkata')->addDays(3)->toDateString(),
            'appointment_time' => '11:00',
            'payment_method' => 'cash',
            'status' => 'confirmed',
            'payment_status' => 'advance_paid',
        ], $overrides);
    }

    private function createOffline(array $overrides = []): Appointment
    {
        $id = $this->postJson('/api/admin/appointments', $this->offline($overrides))->assertCreated()->json('data.id');

        return Appointment::findOrFail($id);
    }

    public function test_an_offline_appointment_paid_in_full_sends_the_receipt_with_the_bill_pdf(): void
    {
        $appointment = $this->createOffline(['payment_status' => 'paid']);

        $this->assertCount(1, $this->uploads());
        $this->assertCount(1, $this->messages(), 'one message — not the receipt AND a booking confirmation');

        $message = $this->messages()[0];
        [$header, $body] = $message['template']['components'];

        $this->assertSame('919944381709', $message['to']);
        $this->assertSame('payment_received', $message['template']['name']);
        $this->assertSame(
            [['type' => 'document', 'document' => ['id' => 'MEDIA123', 'filename' => "bill-{$appointment->reference}.pdf"]]],
            $header['parameters'],
        );
        $this->assertSame(
            ['Walk-in Guest', 'Signature Facial', '1,500.00', $appointment->reference],
            collect($body['parameters'])->pluck('text')->all(),
        );
    }

    public function test_an_offline_appointment_with_advance_paid_sends_the_booking_confirmation_without_a_pdf(): void
    {
        $appointment = $this->createOffline(['payment_status' => 'advance_paid']);

        $this->assertCount(0, $this->uploads(), 'no PDF for an advance payment');
        $this->assertCount(1, $this->messages());

        $message = $this->messages()[0];

        $this->assertSame('booking_confirmed', $message['template']['name']);
        $this->assertCount(1, $message['template']['components']); // body only
        $this->assertSame(
            ['Walk-in Guest', 'Signature Facial', $appointment->appointment_date->toDateString(), '11:00 AM', '300.00', $appointment->reference],
            collect($message['template']['components'][0]['parameters'])->pluck('text')->all(),
        );
    }

    public function test_an_unpaid_confirmed_offline_appointment_still_gets_the_booking_confirmation(): void
    {
        $this->createOffline(['payment_status' => 'unpaid']);

        $this->assertCount(0, $this->uploads());
        $this->assertSame('booking_confirmed', $this->messages()[0]['template']['name']);
    }

    public function test_a_walk_in_already_completed_and_paid_in_full_gets_the_receipt(): void
    {
        $this->createOffline(['status' => 'completed', 'payment_status' => 'paid']);

        $this->assertCount(1, $this->uploads());
        $this->assertSame('payment_received', $this->messages()[0]['template']['name']);
    }

    public function test_offline_appointments_that_are_not_confirmed_or_paid_send_nothing(): void
    {
        $this->createOffline(['status' => 'cancelled', 'payment_status' => 'paid']);
        $this->createOffline(['status' => 'cancelled', 'payment_status' => 'advance_paid']);
        $this->createOffline(['status' => 'completed', 'payment_status' => 'advance_paid']);

        Http::assertNothingSent();
    }

    public function test_when_the_receipt_pdf_cannot_be_uploaded_the_offline_appointment_is_still_created(): void
    {
        $this->uploadStatus = 400;
        $this->uploadReply = ['error' => ['message' => 'Upload failed', 'code' => 100]];

        $appointment = $this->createOffline(['payment_status' => 'paid']);

        $this->assertNotNull($appointment->id);
        $this->assertCount(0, $this->messages()); // a Document-header template can't go out without its document
    }

    public function test_offline_appointments_are_created_normally_while_whatsapp_is_not_configured(): void
    {
        config(['services.whatsapp.token' => '']);

        $this->createOffline(['payment_status' => 'paid']);
        $this->createOffline(['payment_status' => 'advance_paid', 'appointment_time' => '15:00']);

        Http::assertNothingSent();
        $this->assertSame(2, Appointment::count());
    }
}
