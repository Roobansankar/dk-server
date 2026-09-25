<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The Callback URL Meta calls for the WhatsApp Cloud API: the one-time
 * verification handshake (GET) and the signed events afterwards (POST).
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_TOKEN = 'salon-verify-token';

    private const APP_SECRET = 'meta-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.verify_token' => self::VERIFY_TOKEN,
            'services.whatsapp.app_secret' => self::APP_SECRET,
        ]);
    }

    private function verify(array $query)
    {
        return $this->get('/api/whatsapp/webhook?'.http_build_query($query));
    }

    /** POST a payload signed the way Meta signs it (HMAC-SHA256 of the raw body). */
    private function push(array|string $payload, ?string $secret = self::APP_SECRET, ?string $signature = null)
    {
        $body = is_string($payload) ? $payload : json_encode($payload);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signature !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = $signature;
        } elseif ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/whatsapp/webhook', [], [], [], $headers, $body);
    }

    private function statusPayload(array $status): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '2329562221191777',
                'changes' => [[
                    'field' => 'messages',
                    'value' => ['messaging_product' => 'whatsapp', 'statuses' => [$status]],
                ]],
            ]],
        ];
    }

    // --- Verification (GET) --------------------------------------------------

    public function test_meta_verification_gets_the_challenge_back_as_plain_text(): void
    {
        $response = $this->verify([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => self::VERIFY_TOKEN,
            'hub.challenge' => '1158201444',
        ]);

        $response->assertOk();
        $this->assertSame('1158201444', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_a_wrong_verify_token_is_refused_and_the_challenge_is_not_echoed(): void
    {
        $response = $this->verify(['hub.mode' => 'subscribe', 'hub.verify_token' => 'guess', 'hub.challenge' => '1158201444']);

        $response->assertForbidden();
        $this->assertStringNotContainsString('1158201444', $response->getContent());
    }

    public function test_only_the_subscribe_mode_is_accepted(): void
    {
        $this->verify(['hub.mode' => 'unsubscribe', 'hub.verify_token' => self::VERIFY_TOKEN, 'hub.challenge' => '1'])->assertForbidden();
        $this->verify(['hub.verify_token' => self::VERIFY_TOKEN, 'hub.challenge' => '1'])->assertForbidden();
    }

    public function test_verification_needs_the_token_parameter(): void
    {
        $this->verify(['hub.mode' => 'subscribe', 'hub.challenge' => '1'])->assertForbidden();
    }

    public function test_nothing_verifies_while_no_verify_token_is_configured(): void
    {
        config(['services.whatsapp.verify_token' => null]);

        // an empty request token must not "match" an empty setting
        $this->verify(['hub.mode' => 'subscribe', 'hub.verify_token' => '', 'hub.challenge' => '1'])->assertForbidden();
        $this->verify(['hub.mode' => 'subscribe', 'hub.challenge' => '1'])->assertForbidden();
    }

    // --- Events (POST) ---------------------------------------------------------

    public function test_a_signed_failed_status_is_logged_with_metas_reason_and_a_masked_number(): void
    {
        Log::spy();

        $this->push($this->statusPayload([
            'id' => 'wamid.ABC',
            'status' => 'failed',
            'recipient_id' => '919944381709',
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'Recipient is not a valid WhatsApp user']]],
        ]))->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            return $message === 'WhatsApp message failed.'
                && $context['message_id'] === 'wamid.ABC'
                && $context['to'] === '…1709'
                && $context['errors'][0]['code'] === 131026
                && $context['errors'][0]['details'] === 'Recipient is not a valid WhatsApp user'
                && ! str_contains(json_encode($context), '919944381709');
        })->once();
    }

    public function test_delivery_progress_is_logged_at_info_level(): void
    {
        Log::spy();

        $this->push($this->statusPayload(['id' => 'wamid.ABC', 'status' => 'delivered', 'recipient_id' => '919944381709']))->assertOk();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context) => $message === 'WhatsApp message status.' && $context['status'] === 'delivered'
        )->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_customer_reply_is_logged_without_its_text_or_full_number(): void
    {
        Log::spy();

        $this->push([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => [[
                'from' => '919944381709', 'id' => 'wamid.IN', 'type' => 'text', 'text' => ['body' => 'my private message'],
            ]]]]]]],
        ])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            return $message === 'WhatsApp message received.'
                && $context['from'] === '…1709'
                && $context['type'] === 'text'
                && ! str_contains(json_encode($context), 'my private message')
                && ! str_contains(json_encode($context), '919944381709');
        })->once();
    }

    public function test_an_unsigned_event_is_refused(): void
    {
        Log::spy();

        $this->push($this->statusPayload(['id' => 'wamid.ABC', 'status' => 'failed']), secret: null)->assertForbidden();

        Log::shouldNotHaveReceived('warning', fn (string $message) => $message === 'WhatsApp message failed.');
    }

    public function test_an_event_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->push($this->statusPayload(['id' => 'wamid.ABC', 'status' => 'failed']), secret: 'someone-elses-secret')->assertForbidden();
    }

    public function test_a_tampered_body_no_longer_matches_its_signature(): void
    {
        $original = json_encode($this->statusPayload(['id' => 'wamid.ABC', 'status' => 'delivered']));
        $signature = 'sha256='.hash_hmac('sha256', $original, self::APP_SECRET);

        $this->push(str_replace('delivered', 'failed', $original), signature: $signature)->assertForbidden();
    }

    public function test_events_are_refused_while_no_app_secret_is_configured(): void
    {
        config(['services.whatsapp.app_secret' => null]);

        // even a signature made with an empty key must not be trusted
        $this->push($this->statusPayload(['id' => 'wamid.ABC', 'status' => 'failed']), secret: '')->assertForbidden();
    }

    public function test_odd_but_signed_payloads_are_acknowledged_without_error(): void
    {
        foreach ([
            '{}',
            '[]',
            'not json at all',
            json_encode(['entry' => 'x']),
            json_encode(['entry' => ['x', ['changes' => 'y']]]),
            json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => 'z', 'messages' => [1, 'a']]]]]]]),
            json_encode($this->statusPayload(['id' => ['nested'], 'status' => 'failed', 'recipient_id' => [1], 'errors' => 'boom'])),
        ] as $body) {
            $this->push($body)->assertOk();
        }
    }

    public function test_the_webhook_needs_no_login(): void
    {
        // no bearer token anywhere in this file — Meta's servers can't send one
        $this->verify(['hub.mode' => 'subscribe', 'hub.verify_token' => self::VERIFY_TOKEN, 'hub.challenge' => '7'])->assertOk();
    }
}
