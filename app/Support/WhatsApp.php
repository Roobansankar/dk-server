<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        if (! self::isConfigured()) {
            Log::warning('WhatsApp skipped: not configured (token/phone_number_id missing or disabled).');

            return false;
        }

        $to = self::normalisePhone((string) ($appointment->phone ?? ''));
        if (strlen($to) < 10) {
            Log::warning('WhatsApp skipped: bad recipient phone.', ['phone' => $appointment->phone]);

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
     * Low-level template sender.
     *
     * @param  array<int,string>  $bodyParams  ordered {{1}}, {{2}}, … values
     */
    public static function sendTemplate(string $to, string $template, string $lang, array $bodyParams): bool
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
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => $parameters,
                            ],
                        ],
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
