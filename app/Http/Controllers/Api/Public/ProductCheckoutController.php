<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductCheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\ProductCheckout;
use App\Services\ProductInventoryService;
use App\Support\OrderPricing;
use App\Support\Razorpay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Razorpay Standard Checkout for product purchases (normal products and
 * combo selections), reusing App\Support\Razorpay — the same order-creation
 * and HMAC signature check as the appointment confirmation fee.
 *
 * store() prices the basket server-side and opens a Razorpay Order for that
 * amount, recording a ProductCheckout (not an order).
 *
 * verify() checks the Checkout callback signature and — only then — creates
 * the product Order from the checkout's price snapshot, marks it paid, and
 * deducts the required product stock atomically.
 *
 * A failed, abandoned or forged payment never produces an order or reduces
 * stock.
 */
class ProductCheckoutController extends Controller
{
    public function store(StoreProductCheckoutRequest $request): JsonResponse
    {
        $priced = OrderPricing::price($request->validated('items'));
        $amountPaise = (int) round(((float) $priced['subtotal']) * 100);

        if ($amountPaise <= 0) {
            throw ValidationException::withMessages([
                'items' => 'The order total must be greater than zero.',
            ]);
        }

        $reference = ProductCheckout::generateReference();

        try {
            $razorpayOrder = Razorpay::createOrder($amountPaise, $reference, [
                'checkout_reference' => $reference,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        // Ownership comes from the token only — there is no user_id in
        // StoreProductCheckoutRequest::rules().
        $checkout = ProductCheckout::create([
            'reference' => $reference,
            'user_id' => $request->user()->id,
            'customer_name' => $request->validated('customer_name'),
            'phone' => $request->validated('phone'),
            'lines' => $priced['lines'],
            'amount' => $priced['subtotal'],
            'razorpay_order_id' => $razorpayOrder['id'],
        ]);

        return response()->json([
            'data' => [
                'checkout_id' => $checkout->id,
                'reference' => $checkout->reference,
                'key' => Razorpay::keyId(),
                'order_id' => $checkout->razorpay_order_id,
                'amount' => $amountPaise,
                'currency' => 'INR',
                'name' => 'DK StyleHub',
                'description' => 'Product order',
                'items' => array_map(fn ($line) => [
                    'type' => $line['item_type'],
                    'product_id' => $line['product_id'],
                    'combo_id' => $line['combo_id'],
                    'name' => $line['name'],
                    'unit_price' => (float) $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => (float) $line['line_total'],
                    'selected_products' => array_map(fn ($p) => [
                        'product_id' => $p['product_id'],
                        'name' => $p['product_name'],
                        'price' => (float) $p['price'],
                    ], $line['selected_products']),
                ], $priced['lines']),
                'total' => (float) $priced['subtotal'],
                'prefill' => [
                    'name' => $checkout->customer_name,
                    'contact' => $checkout->phone,
                ],
            ],
        ], 201);
    }

    public function verify(
        Request $request,
        ProductCheckout $checkout,
        ProductInventoryService $inventory
    ): JsonResponse {
        // 404, not 403, so a guessed id doesn't confirm the checkout exists.
        abort_unless($checkout->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        if ($checkout->razorpay_order_id !== $data['razorpay_order_id']) {
            throw ValidationException::withMessages([
                'razorpay_order_id' => 'This payment does not match this checkout.',
            ]);
        }

        if (! Razorpay::verifySignature(
            $data['razorpay_order_id'],
            $data['razorpay_payment_id'],
            $data['razorpay_signature']
        )) {
            throw ValidationException::withMessages([
                'razorpay_signature' => 'Payment verification failed. Please try again.',
            ]);
        }

        [$order, $created] = DB::transaction(function () use (
            $checkout,
            $data,
            $inventory
        ) {
            // Row lock serialises concurrent verify calls for this checkout.
            // The unique orders.razorpay_order_id index remains the final
            // database backstop against duplicate orders.
            $locked = ProductCheckout::whereKey($checkout->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent retry: the same verified payment replayed returns
            // the existing order and does not deduct stock again.
            if ($locked->order_id) {
                if ($locked->razorpay_payment_id !== $data['razorpay_payment_id']) {
                    throw ValidationException::withMessages([
                        'razorpay_payment_id' => 'This checkout has already been paid for.',
                    ]);
                }

                return [Order::findOrFail($locked->order_id), false];
            }

            /*
             * Build the exact inventory requirements from the checkout
             * snapshot.
             *
             * Normal product:
             *   product_id + line quantity
             *
             * Combo:
             *   every selected product consumes the combo line quantity.
             */
            $inventoryItems = [];

            foreach ($locked->lines as $line) {
                $quantity = (int) $line['quantity'];

                if (($line['item_type'] ?? null) === 'combo') {
                    foreach ($line['selected_products'] as $selectedProduct) {
                        $inventoryItems[] = [
                            'product_id' => (int) $selectedProduct['product_id'],
                            'quantity' => $quantity,
                        ];
                    }

                    continue;
                }

                if (! empty($line['product_id'])) {
                    $inventoryItems[] = [
                        'product_id' => (int) $line['product_id'],
                        'quantity' => $quantity,
                    ];
                }
            }

            /*
             * Create the paid order first inside the same transaction.
             *
             * If inventory deduction fails afterwards, the entire
             * transaction rolls back, so this order will not remain.
             */
            $order = Order::create([
                'user_id' => $locked->user_id,
                'customer_name' => $locked->customer_name,
                'phone' => $locked->phone,
                'subtotal' => $locked->amount,
                'total' => $locked->amount,

                // The Razorpay order was opened for exactly this amount and
                // the signature binds the payment to that order.
                'amount_paid' => $locked->amount,
                'payment_status' => Order::PAYMENT_PAID,
                'razorpay_order_id' => $locked->razorpay_order_id,
                'razorpay_payment_id' => $data['razorpay_payment_id'],
                'paid_at' => now(),
            ]);

            foreach ($locked->lines as $line) {
                $item = $order->items()->create(
                    Arr::except($line, 'selected_products')
                );

                $item->selectedProducts()->createMany(
                    $line['selected_products']
                );
            }

            /*
             * Deduct stock only after the payment has been cryptographically
             * verified, and while still inside the same transaction.
             *
             * If any product has insufficient stock, ProductInventoryService
             * throws a validation exception and the order creation above
             * rolls back automatically.
             */
            $inventory->recordSale(
                $inventoryItems,
                $order,
                null
            );

            $locked->razorpay_payment_id = $data['razorpay_payment_id'];
            $locked->order_id = $order->id;
            $locked->save();

            return [$order, true];
        });

        return (new OrderResource($order->load('items.selectedProducts')))
            ->additional([
                'message' => $created
                    ? 'Payment verified. Your order has been placed.'
                    : 'Payment already verified. Your order has been placed.',
            ])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }
}
