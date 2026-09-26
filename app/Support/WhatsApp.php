<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin wrapper around the WhatsApp Cloud API (Meta) template messages.
 *
 * Used for: "your booking is confirmed + advance paid Rs.X" messages —
 * sent to the customer's own `phone` stored on the appointment.
 *
 * Setup (Meta Dashboard → WhatsApp → API Setup):
 *   WHATSAPP_TOKEN           = access token (System User token, long-lived)
 *   WHATSAPP_PHONE_NUMBER_ID = "Phone number ID" (NOT the phone number itself)
 *   WHATSAPP_WABA_ID         = WhatsApp Business Account ID (reference only)
 *
 * Template must be created + APPROVED first (see guide in chat / README).
 * Default: name `booking_confirmed`, language `en`, category UTILITY.
 */
class WhatsApp
{
    private const API_VERSION = 'v22.0';

    public static function isConfigured(): bool
    {
        if (! (bool) config('services.whatsapp.enabled', true)) {
            return false;
        }

        return (string) config('services.whatsapp.token') !== ''
            && (string) config('services.whatsapp.phone_number_id') !== '';
    }

    /**
     * Normalise an Indian customer phone to E.164 without "+".
     * "98765 43210" → "919876543210". Already-prefixed "919..." kept as-is.
     */
    public static function normalisePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw ?? '') ?? '';

        // Strip leading 00 international prefix → e.g. 0091… → 91…
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // 10-digit local mobile → assume India.
        if (strlen($digits) === 10) {
            return '91'.$digits;
        }

        // 11-digit starting with 0 → drop trunk 0, assume India.
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '91'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Send the booking-confirmed template for this appointment.
     * Never throws — returns true on success, false otherwise (logged).
     * Safe to call right after confirm/verify; booking is unaffected on failure.
     */
    public static function sendBookingConfirmed(Appointment $appointment): bool
    {
        $to = self::recipientFor($appointment);
        if ($to === null) {
            return false;
        }

        $template = (string) config('services.whatsapp.template', 'booking_confirmed');
        $lang = (string) config('services.whatsapp.language', 'en');

        $date = $appointment->appointment_date?->toDateString() ?? '';
        $time = $appointment->appointment_time
            ? \Illuminate\Support\Carbon::parse($appointment->appointment_time)->format('h:i A')
            : '';

        // Must match {{1}}…{{6}} order in your approved template body exactly.
        $params = [
            (string) ($appointment->customer_name ?? 'Guest'),
            (string) ($appointment->service_name ?? 'your service'),
            $date,
            $time,
            number_format((float) ($appointment->advance_amount ?? 0), 2),
            (string) ($appointment->reference ?? ''),
        ];

        return self::sendTemplate($to, $template, $lang, $params);
    }

    /**
     * Send the payment-received template with the bill PDF attached: the
     * customer has settled the whole bill — typically the balance paid at the
     * studio after an online advance, when staff mark the appointment "Paid in
     * full". The approved template needs a DOCUMENT header (the PDF) plus a
     * body with {{1}}…{{4}}.
     * Never throws — returns true on success, false otherwise (logged).
     */
    public static function sendPaymentReceived(Appointment $appointment): bool
    {
        $to = self::recipientFor($appointment);
        if ($to === null) {
            return false;
        }

        // Must match {{1}}…{{4}} order in your approved template body exactly.
        $params = [
            (string) ($appointment->customer_name ?? 'Guest'),
            (string) ($appointment->service_name ?? 'your service'),
            number_format((float) $appointment->amount_received, 2), // the full bill once "paid"
            (string) ($appointment->reference ?? ''),
        ];

        return self::sendWithBill(
            $to,
            (string) config('services.whatsapp.template_paid', 'payment_received'),
            $params,
            BillPdf::filename($appointment),
            fn () => BillPdf::make($appointment)->output(),
        );
    }

    /**
     * Send the order-paid template with the bill PDF attached: a shop order
     * (Buy Now / cart checkout) whose Razorpay payment was just verified. The
     * approved template needs a DOCUMENT header (the PDF) plus a body with
     * {{1}}…{{4}}.
     * Never throws — returns true on success, false otherwise (logged).
     */
    public static function sendOrderPaid(Order $order): bool
    {
        $to = self::recipientFor($order);
        if ($to === null) {
            return false;
        }

        // Must match {{1}}…{{4}} order in your approved template body exactly.
        $params = [
            (string) ($order->customer_name ?? 'Guest'),
            (string) $order->order_number,
            self::itemsSummary($order),
            number_format((float) $order->amount_paid, 2),
        ];

        return self::sendWithBill(
            $to,
            (string) config('services.whatsapp.template_order', 'order_paid'),
            $params,
            OrderBillPdf::filename($order),
            fn () => OrderBillPdf::make($order)->output(),
        );
    }

    /** "Pro-1 x2, Pro-2 x1, Hair Care Package x1 +2 more" — one line, as WhatsApp variables can't hold line breaks. */
    private static function itemsSummary(Order $order): string
    {
        $lines = $order->items->map(fn ($item) => $item->name.' x'.$item->quantity);
        $summary = $lines->take(3)->implode(', ');

        if ($lines->count() > 3) {
            $summary .= ' +'.($lines->count() - 3).' more';
        }

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $summary)), 150);
    }

    /**
     * Send a template whose header is a PDF bill: build the PDF, upload it to
     * WhatsApp, then send the template with it attached. The template's
     * Document header can't be left empty, so no PDF means no message.
     *
     * @param  array<int,string>  $params  ordered body values {{1}}, {{2}}, …
     * @param  \Closure(): string  $pdf  builds the PDF bytes
     */
    private static function sendWithBill(string $to, string $template, array $params, string $filename, \Closure $pdf): bool
    {
        try {
            $bytes = $pdf();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        $mediaId = self::uploadDocument($bytes, $filename);
        if ($mediaId === null) {
            return false;
        }

        $lang = (string) config('services.whatsapp.language', 'en');

        return self::sendTemplate($to, $template, $lang, $params, ['id' => $mediaId, 'filename' => $filename]);
    }

    /**
     * Upload a PDF to WhatsApp's media store and return its media id (or null,
     * logged). Uploading — rather than sending a link — means the bill never
     * needs a public URL, so it also works from a local machine and the
     * customer's bill isn't reachable by anyone else.
     */
    private static function uploadDocument(string $pdf, string $filename): ?string
    {
        $token = (string) config('services.whatsapp.token');
        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
                ->post('https://graph.facebook.com/'.self::API_VERSION."/{$phoneNumberId}/media", [
                    'messaging_product' => 'whatsapp',
                    'type' => 'application/pdf',
                ]);

            $id = $response->json('id');

            if ($response->failed() || ! is_string($id) || $id === '') {
                Log::warning('WhatsApp bill upload failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $id;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Where a message for this appointment or order goes (E.164, no "+"), or
     * null — logged — when WhatsApp is off or the customer's number is unusable.
     */
    private static function recipientFor(Appointment|Order $subject): ?string
    {
        if (! self::isConfigured()) {
            Log::warning('WhatsApp skipped: not configured (token/phone_number_id missing or disabled).');

            return null;
        }

        $to = self::normalisePhone((string) ($subject->phone ?? ''));
        if (strlen($to) < 10) {
            Log::warning('WhatsApp skipped: bad recipient phone.', ['phone' => $subject->phone]);

            return null;
        }

        return $to;
    }

    /**
     * Low-level template sender.
     *
     * @param  array<int,string>  $bodyParams  ordered {{1}}, {{2}}, … values
     * @param  array{id: string, filename: string}|null  $headerDocument  an uploaded PDF, for templates with a Document header
     */
    public static function sendTemplate(string $to, string $template, string $lang, array $bodyParams, ?array $headerDocument = null): bool
    {
        $token = (string) config('services.whatsapp.token');
        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');

        if ($token === '' || $phoneNumberId === '') {
            return false;
        }

        $parameters = array_map(
            fn ($text) => ['type' => 'text', 'text' => (string) $text],
            array_values($bodyParams)
        );

        $components = [['type' => 'body', 'parameters' => $parameters]];

        if ($headerDocument !== null) {
            array_unshift($components, [
                'type' => 'header',
                'parameters' => [['type' => 'document', 'document' => $headerDocument]],
            ]);
        }

        try {
            $response = Http::withToken($token)
                ->asJson()
                ->timeout(15)
                ->post('https://graph.facebook.com/'.self::API_VERSION."/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $template,
                        'language' => ['code' => $lang],
                        'components' => $components,
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('WhatsApp send failed.', [
                    'to' => $to,
                    'template' => $template,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            Log::info('WhatsApp sent.', ['to' => $to, 'template' => $template, 'response' => $response->json()]);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
