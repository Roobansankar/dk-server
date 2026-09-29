<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The WhatsApp Cloud API (Meta) webhook — the "Callback URL" in Meta's
 * WhatsApp → Configuration screen: https://<your-domain>/api/whatsapp/webhook
 *
 *   GET  → Meta's one-time "Verify and save" handshake. Answered only when
 *          `hub.verify_token` equals WHATSAPP_VERIFY_TOKEN.
 *   POST → events Meta pushes afterwards (delivery statuses, customer
 *          replies). Accepted only with a valid `X-Hub-Signature-256`, i.e.
 *          when signed with the app secret (WHATSAPP_APP_SECRET).
 *
 * Events are only written to the log for now — most usefully a `failed`
 * status, which carries Meta's reason a message never reached the customer.
 * Phone numbers are masked and message text is never logged.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        // PHP turns the dots in `hub.mode` into underscores, so accept both.
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expected = (string) config('services.whatsapp.verify_token');

        // An unset token must never match: an empty request token would equal an empty setting.
        if ($expected === '' || $mode !== 'subscribe' || ! hash_equals($expected, $token)) {
            Log::warning('WhatsApp webhook verification refused.', [
                'reason' => $expected === '' ? 'WHATSAPP_VERIFY_TOKEN is not set' : 'mode or token did not match',
            ]);

            return response('Forbidden', 403);
        }

        return response($challenge, 200, [
            'Content-Type' => 'text/plain',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function receive(Request $request)
    {
        if (! $this->hasValidSignature($request)) {
            return response('Forbidden', 403);
        }

        $payload = json_decode($request->getContent(), true);

        if (is_array($payload)) {
            $this->record($payload);
        }

        // Meta only needs a quick 200; anything else makes it retry the same event.
        return response('EVENT_RECEIVED', 200);
    }

    /** `X-Hub-Signature-256: sha256=<HMAC of the raw body, keyed with the app secret>`. */
    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.whatsapp.app_secret');

        if ($secret === '') {
            Log::warning('WhatsApp webhook event rejected: WHATSAPP_APP_SECRET is not set.');

            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            Log::warning('WhatsApp webhook event rejected: missing signature.');

            return false;
        }

        if (! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), substr($header, 7))) {
            Log::warning('WhatsApp webhook event rejected: signature does not match.');

            return false;
        }

        return true;
    }

    /** @param  array<mixed>  $payload */
    private function record(array $payload): void
    {
        foreach (self::rows($payload['entry'] ?? null) as $entry) {
            foreach (self::rows($entry['changes'] ?? null) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                foreach (self::rows($change['value']['statuses'] ?? null) as $status) {
                    $this->recordStatus($status);
                }

                foreach (self::rows($change['value']['messages'] ?? null) as $message) {
                    Log::info('WhatsApp message received.', [
                        'message_id' => self::text($message['id'] ?? null),
                        'from' => self::mask($message['from'] ?? null),
                        'type' => self::text($message['type'] ?? null),
                    ]);
                }
            }
        }
    }

    /** @param  array<mixed>  $status */
    private function recordStatus(array $status): void
    {
        $context = [
            'message_id' => self::text($status['id'] ?? null),
            'status' => self::text($status['status'] ?? null),
            'to' => self::mask($status['recipient_id'] ?? null),
        ];

        if (($status['status'] ?? null) !== 'failed') {
            Log::info('WhatsApp message status.', $context);

            return;
        }

        Log::warning('WhatsApp message failed.', $context + [
            'errors' => array_map(fn (array $error) => [
                'code' => $error['code'] ?? null,
                'title' => self::text($error['title'] ?? null),
                'details' => self::text(data_get($error, 'error_data.details', $error['message'] ?? null)),
            ], self::rows($status['errors'] ?? null)),
        ]);
    }

    /**
     * The array items of an untrusted value — anything that isn't a list of
     * arrays yields nothing instead of a type error.
     *
     * @return array<int, array<mixed>>
     */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** "919944381709" → "…1709": enough to recognise a number, not to expose it. */
    private static function mask(mixed $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) self::text($number));

        return $digits === '' || $digits === null ? null : '…'.substr($digits, -4);
    }
}
