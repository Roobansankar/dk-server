<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the Razorpay Orders REST API (TEST Mode). No SDK
 * dependency — a plain authenticated HTTP call plus the documented HMAC
 * signature check is all Standard Checkout needs server-side.
 *
 * @see https://razorpay.com/docs/payments/server-integration/php/payment-gateway/build-integration/
 */
class Razorpay
{
    private const API_BASE = 'https://api.razorpay.com/v1';

    public static function keyId(): string
    {
        return (string) config('services.razorpay.key');
    }

    private static function keySecret(): string
    {
        return (string) config('services.razorpay.secret');
    }

    /**
     * Create a Razorpay Order for the given amount (in paise). Returns the
     * decoded API response, which includes the Razorpay-assigned `id` to
     * hand to Checkout as `order_id`.
     *
     * @throws RuntimeException on a non-2xx response or transport failure.
     */
    public static function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        $response = Http::withBasicAuth(self::keyId(), self::keySecret())
            ->asJson()
            ->timeout(15)
            ->post(self::API_BASE.'/orders', [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => $notes,
                // Test Mode has no auto-capture surprises either way, but
                // being explicit keeps the intent obvious.
                'payment_capture' => true,
            ]);

        if ($response->failed()) {
            report(new RuntimeException('Razorpay order creation failed: '.$response->body()));

            throw new RuntimeException('Unable to start the payment right now.');
        }

        return $response->json();
    }

    /**
     * Verify a Standard Checkout success callback's signature against the
     * order id we generated server-side — never the order id or amount a
     * client claims. Matches Razorpay's documented HMAC-SHA256 recipe.
     */
    public static function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = self::keySecret();
        if ($secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);

        return hash_equals($expected, $signature);
    }
}
