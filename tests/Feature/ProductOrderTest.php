<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCheckout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class ProductOrderTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private Product $p1;

    private Product $p2;

    private Product $p3;

    private Combo $combo;

    protected function setUp(): void
    {
        parent::setUp();

        // Distinct Razorpay order id per checkout (keyed by receipt), echoing
        // back the amount the server asked for.
        Http::fake([
            'api.razorpay.com/*' => fn (HttpRequest $request) => Http::response([
                'id' => 'order_'.$request->data()['receipt'],
                'entity' => 'order',
                'amount' => $request->data()['amount'],
                'currency' => 'INR',
                'status' => 'created',
            ], 200),
        ]);

        // Storefront prices deliberately differ from the combo prices.
        // Test products start with enough stock for successful order tests.
        $this->p1 = Product::factory()->create([
            'name' => 'Pro-1',
            'mrp' => 900,
            'selling_price' => 800,
            'stock_quantity' => 100,
        ]);

        $this->p2 = Product::factory()->create([
            'name' => 'Pro-2',
            'mrp' => 900,
            'selling_price' => 850,
            'stock_quantity' => 100,
        ]);

        $this->p3 = Product::factory()->create([
            'name' => 'Pro-3',
            'mrp' => 900,
            'selling_price' => 700,
            'stock_quantity' => 100,
        ]);

        $this->combo = Combo::create([
            'name' => 'Hair Care Package',
            'slug' => 'hair-care-package',
            'bundle_price' => 1400,
        ]);

        foreach ([[$this->p1, 500], [$this->p2, 600], [$this->p3, 450]] as $i => [$product, $price]) {
            $this->combo->items()->create([
                'product_id' => $product->id,
                'price' => $price,
                'sort_order' => $i,
            ]);
        }
    }

    private function payload(array $items, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Asha Rao',
            'phone' => '9876543210',
            'items' => $items,
        ], $overrides);
    }

    private function comboItem(array $productIds, int $quantity = 1): array
    {
        return [
            'type' => 'combo',
            'combo_id' => $this->combo->id,
            'product_ids' => $productIds,
            'quantity' => $quantity,
        ];
    }

    private function productItem(Product $product, int $quantity = 1): array
    {
        return [
            'type' => 'product',
            'product_id' => $product->id,
            'quantity' => $quantity,
        ];
    }

    private function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac(
            'sha256',
            $orderId.'|'.$paymentId,
            config('services.razorpay.secret')
        );
    }

    private function checkout(array $items, array $overrides = []): TestResponse
    {
        return $this->postJson(
            '/api/checkout',
            $this->payload($items, $overrides)
        );
    }

    private function verify(
        int $checkoutId,
        string $orderId,
        string $paymentId = 'pay_ok',
        ?string $signature = null
    ): TestResponse {
        return $this->postJson("/api/checkout/{$checkoutId}/verify", [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature ?? $this->signature($orderId, $paymentId),
        ]);
    }

    /** Checkout + verified payment in one step; returns the created order. */
    private function paidOrder(array $items): Order
    {
        $data = $this->checkout($items)->assertCreated()->json('data');

        $this->verify(
            $data['checkout_id'],
            $data['order_id']
        )->assertCreated();

        return Order::where(
            'razorpay_order_id',
            $data['order_id']
        )->firstOrFail();
    }

    // --- Checkout / Razorpay order --------------------------------------

    public function test_checkout_opens_a_razorpay_order_for_the_server_calculated_amount_and_creates_no_order(): void
    {
        // Pro-1 + Pro-3 at combo prices = 500 + 450 = 950 (not 800 + 700).
        $response = $this->checkout([
            $this->comboItem([$this->p1->id, $this->p3->id]),
        ])
            ->assertCreated()
            ->assertJsonPath('data.amount', 95000)
            ->assertJsonPath(
                'data.total',
                fn ($v) => (float) $v === 950.0
            )
            ->assertJsonPath(
                'data.items.0.selected_products.0.price',
                fn ($v) => (float) $v === 500.0
            )
            ->assertJsonPath(
                'data.items.0.selected_products.1.price',
                fn ($v) => (float) $v === 450.0
            );

        Http::assertSent(
            fn (HttpRequest $r) => $r->data()['amount'] === 95000
        );

        $this->assertStringStartsWith(
            'order_CHK-',
            $response->json('data.order_id')
        );

        $this->assertDatabaseCount('product_checkouts', 1);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_server_calculated_amount_is_authoritative(): void
    {
        $items = [
            $this->comboItem(
                [$this->p1->id, $this->p2->id, $this->p3->id],
                2
            ) + [
                'price' => 1,
                'unit_price' => 1,
            ],
            $this->productItem($this->p2) + [
                'price' => 1,
                'selling_price' => 1,
            ],
        ];

        // (500 + 600 + 450) × 2 + 850 = 3650,
        // regardless of client-sent prices.
        $this->checkout(
            $items,
            [
                'amount' => 100,
                'subtotal' => 1,
                'total' => 1,
            ]
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.amount',
                365000
            )
            ->assertJsonPath(
                'data.items.0.unit_price',
                fn ($v) => (float) $v === 1400.0
            )
            ->assertJsonPath(
                'data.items.1.unit_price',
                fn ($v) => (float) $v === 850.0
            );

        Http::assertSent(
            fn (HttpRequest $r) => $r->data()['amount'] === 365000
        );

        $this->assertSame(
            '3650.00',
            ProductCheckout::first()->amount
        );
    }

    public function test_combo_selection_is_validated_against_the_combo_configuration(): void
    {
        $outsider = Product::factory()->create();

        $this->checkout([
            $this->comboItem([
                $this->p1->id,
                $outsider->id,
            ]),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_ids');

        $this->checkout([
            $this->comboItem([]),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_ids');

        $this->p2->update([
            'status' => false,
        ]);

        $this->checkout([
            $this->comboItem([$this->p2->id]),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_ids');

        Http::assertNothingSent();

        $this->assertDatabaseCount(
            'product_checkouts',
            0
        );
    }

    // --- Guest checkout (no account) ---------------------------------------

    public function test_a_guest_can_open_a_checkout_for_the_server_calculated_amount(): void
    {
        // Combo (Pro-3 450 + Pro-1 500 = 950) + Pro-2 × 2 (850 each) = 2650.
        // The client-sent totals are ignored.
        $response = $this->checkout([
            $this->comboItem([$this->p3->id, $this->p1->id]),
            $this->productItem($this->p2, 2),
        ], ['total' => 1, 'subtotal' => 1, 'amount' => 1])
            ->assertCreated()
            ->assertJsonPath('data.amount', 265000)
            ->assertJsonPath('data.total', fn ($v) => (float) $v === 2650.0)
            ->assertJsonPath('data.prefill.name', 'Asha Rao')
            ->assertJsonPath('data.prefill.contact', '9876543210');

        Http::assertSent(fn (HttpRequest $r) => $r->data()['amount'] === 265000);

        $this->assertDatabaseHas('product_checkouts', [
            'id' => $response->json('data.checkout_id'),
            'user_id' => null,
            'customer_name' => 'Asha Rao',
            'phone' => '9876543210',
            'amount' => 2650,
        ]);

        // Opening a checkout creates no order and reserves no stock.
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('product_stock_movements', 0);
        $this->assertSame(100, $this->p2->fresh()->stock_quantity);
    }

    public function test_a_guest_checkout_is_still_validated(): void
    {
        $this->postJson('/api/checkout', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'phone', 'items']);

        $this->checkout([$this->productItem($this->p1)], ['phone' => 'not-a-phone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        Http::assertNothingSent();
        $this->assertDatabaseCount('product_checkouts', 0);
    }

    public function test_a_guest_payment_creates_a_paid_order_with_no_account_and_deducts_stock(): void
    {
        $data = $this->checkout([
            $this->comboItem([$this->p3->id, $this->p1->id]),
            $this->productItem($this->p2, 2),
        ])->assertCreated()->json('data');

        $response = $this->verify($data['checkout_id'], $data['order_id'], 'pay_guest')
            ->assertCreated()
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.customer_name', 'Asha Rao')
            ->assertJsonPath('data.phone', '9876543210')
            ->assertJsonPath('data.source', 'online')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.total', fn ($v) => (float) $v === 2650.0)
            ->assertJsonPath('data.amount_paid', fn ($v) => (float) $v === 2650.0)
            ->assertJsonPath('data.razorpay_order_id', $data['order_id'])
            ->assertJsonPath('data.razorpay_payment_id', 'pay_guest')
            ->assertJsonCount(2, 'data.items');

        $order = Order::sole();
        $this->assertNull($order->user_id);
        $this->assertSame($response->json('data.id'), $order->id);
        $this->assertSame($order->id, ProductCheckout::find($data['checkout_id'])->order_id);

        // Stock is deducted once per product, each with a SALE movement
        // tied to this order.
        $this->assertSame(99, $this->p1->fresh()->stock_quantity);
        $this->assertSame(98, $this->p2->fresh()->stock_quantity);
        $this->assertSame(99, $this->p3->fresh()->stock_quantity);

        foreach ([[$this->p1, -1, 99], [$this->p2, -2, 98], [$this->p3, -1, 99]] as [$product, $quantity, $balance]) {
            $this->assertDatabaseHas('product_stock_movements', [
                'product_id' => $product->id,
                'type' => 'sale',
                'quantity' => $quantity,
                'balance_after' => $balance,
                'order_id' => $order->id,
            ]);
        }
        $this->assertDatabaseCount('product_stock_movements', 3);

        // The same verified payment replayed is a no-op.
        $this->verify($data['checkout_id'], $data['order_id'], 'pay_guest')
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('product_stock_movements', 3);
        $this->assertSame(98, $this->p2->fresh()->stock_quantity);
    }

    public function test_a_forged_guest_payment_creates_no_order_and_leaves_stock_alone(): void
    {
        $data = $this->checkout([$this->productItem($this->p1, 3)])->json('data');
        $other = $this->checkout([$this->productItem($this->p3)])->json('data');

        // Made-up signature.
        $this->verify($data['checkout_id'], $data['order_id'], 'pay_x', 'forged-signature')
            ->assertStatus(422)->assertJsonValidationErrors('razorpay_signature');

        // A real signature, but for a different payment id.
        $this->verify($data['checkout_id'], $data['order_id'], 'pay_swapped', $this->signature($data['order_id'], 'pay_ok'))
            ->assertStatus(422)->assertJsonValidationErrors('razorpay_signature');

        // A correctly signed payment for another checkout's Razorpay order.
        $this->verify($data['checkout_id'], $other['order_id'])
            ->assertStatus(422)->assertJsonValidationErrors('razorpay_order_id');

        // A correctly signed, made-up Razorpay order id.
        $this->verify($data['checkout_id'], 'order_made_up')
            ->assertStatus(422)->assertJsonValidationErrors('razorpay_order_id');

        // A checkout that does not exist.
        $this->verify(999999, $data['order_id'])->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('product_stock_movements', 0);
        $this->assertSame(100, $this->p1->fresh()->stock_quantity);
        $this->assertSame(100, $this->p3->fresh()->stock_quantity);
        $this->assertNull(ProductCheckout::find($data['checkout_id'])->order_id);
    }

    public function test_a_guest_order_that_cannot_be_fulfilled_rolls_back_without_touching_stock(): void
    {
        $data = $this->checkout([
            $this->productItem($this->p1, 2),
            $this->productItem($this->p2, 2),
        ])->json('data');

        // Stock runs out between opening the checkout and paying.
        $this->p2->forceFill(['stock_quantity' => 1])->save();

        $this->verify($data['checkout_id'], $data['order_id'])
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('product_stock_movements', 0);
        $this->assertSame(100, $this->p1->fresh()->stock_quantity);
        $this->assertSame(1, $this->p2->fresh()->stock_quantity);
    }

    /** There are no customer accounts: a bearer token never gives an order an owner. */
    public function test_an_order_never_gets_an_owner_even_when_a_token_is_sent(): void
    {
        foreach ([$this->customer(), $this->admin()] as $i => $user) {
            $this->actingAsToken($user);

            $data = $this->checkout([$this->productItem($this->p1)])->assertCreated()->json('data');
            $this->verify($data['checkout_id'], $data['order_id'], "pay_{$i}")
                ->assertCreated()
                ->assertJsonPath('data.user_id', null);
        }

        $this->assertSame(0, ProductCheckout::whereNotNull('user_id')->count());
        $this->assertSame(0, Order::whereNotNull('user_id')->count());
    }

    public function test_admin_sees_guest_orders_and_admin_order_endpoints_reject_guests(): void
    {
        $order = $this->paidOrder([$this->productItem($this->p1)]);
        $this->assertNull($order->user_id);

        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->getJson("/api/admin/orders/{$order->id}")->assertUnauthorized();
        $this->getJson("/api/admin/orders/{$order->id}/bill")->assertUnauthorized();
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered'])->assertUnauthorized();
        $this->postJson('/api/admin/offline-billing', [])->assertUnauthorized();
        // The customer order-history endpoint no longer exists.
        $this->getJson('/api/account/orders')->assertNotFound();
        $this->getJson("/api/account/orders/{$order->id}")->assertNotFound();

        $this->actingAsToken($this->superadmin());

        $this->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.user_id', null)
            ->assertJsonPath('data.0.customer_name', 'Asha Rao');

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
    }

    // --- Verification ------------------------------------------------------

    public function test_successful_payment_creates_exactly_one_paid_order_with_snapshots(): void
    {
        $data = $this->checkout([
            $this->comboItem([
                $this->p3->id,
                $this->p1->id,
            ]),
            $this->productItem($this->p2, 2),
        ])->json('data');

        // 950 + 850 × 2 = 2650
        $this->verify(
            $data['checkout_id'],
            $data['order_id'],
            'pay_123'
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.user_id',
                null
            )
            ->assertJsonPath(
                'data.customer_name',
                'Asha Rao'
            )
            ->assertJsonPath(
                'data.phone',
                '9876543210'
            )
            ->assertJsonPath(
                'data.payment_status',
                'paid'
            )
            ->assertJsonPath(
                'data.status',
                'confirmed'
            )
            ->assertJsonPath(
                'data.total',
                fn ($v) => (float) $v === 2650.0
            )
            ->assertJsonPath(
                'data.amount_paid',
                fn ($v) => (float) $v === 2650.0
            )
            ->assertJsonPath(
                'data.razorpay_order_id',
                $data['order_id']
            )
            ->assertJsonPath(
                'data.razorpay_payment_id',
                'pay_123'
            );

        $this->assertDatabaseCount('orders', 1);

        $order = Order::with(
            'items.selectedProducts'
        )->first();

        $this->assertStringStartsWith(
            'ORD-',
            $order->order_number
        );

        $this->assertNotNull(
            $order->paid_at
        );

        [$comboLine, $productLine] = $order->items;

        $this->assertSame(
            [
                'combo',
                $this->combo->id,
                'Hair Care Package',
                '950.00',
                1,
            ],
            [
                $comboLine->item_type,
                $comboLine->combo_id,
                $comboLine->name,
                $comboLine->unit_price,
                $comboLine->quantity,
            ]
        );

        $this->assertSame(
            [
                [
                    $this->p1->id,
                    'Pro-1',
                    '500.00',
                ],
                [
                    $this->p3->id,
                    'Pro-3',
                    '450.00',
                ],
            ],
            $comboLine->selectedProducts
                ->map(
                    fn ($p) => [
                        $p->product_id,
                        $p->product_name,
                        $p->price,
                    ]
                )
                ->all()
        );

        $this->assertSame(
            [
                'product',
                $this->p2->id,
                '850.00',
                2,
                '1700.00',
            ],
            [
                $productLine->item_type,
                $productLine->product_id,
                $productLine->unit_price,
                $productLine->quantity,
                $productLine->line_total,
            ]
        );
    }

    public function test_failed_or_abandoned_payment_creates_no_order(): void
    {
        $data = $this->checkout([
            $this->productItem($this->p1),
        ])->json('data');

        // Razorpay failure / closed modal: no success callback payload to verify.
        $this->postJson(
            "/api/checkout/{$data['checkout_id']}/verify",
            [
                'razorpay_order_id' => $data['order_id'],
            ]
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'razorpay_payment_id',
                'razorpay_signature',
            ]);

        $this->assertDatabaseCount(
            'orders',
            0
        );

        $this->assertNull(
            ProductCheckout::first()->order_id
        );
    }

    public function test_invalid_signature_creates_no_order(): void
    {
        $data = $this->checkout([
            $this->productItem($this->p1),
        ])->json('data');

        $this->verify(
            $data['checkout_id'],
            $data['order_id'],
            'pay_x',
            'forged-signature'
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'razorpay_signature'
            );

        $this->assertDatabaseCount(
            'orders',
            0
        );
    }

    public function test_a_different_razorpay_order_cannot_be_used(): void
    {
        $cheap = $this->checkout([
            $this->productItem($this->p3),
        ])->json('data');

        $pricey = $this->checkout([
            $this->productItem($this->p1, 5),
        ])->json('data');

        // Validly signed payment for the cheap order,
        // replayed against the expensive checkout.
        $this->verify(
            $pricey['checkout_id'],
            $cheap['order_id']
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'razorpay_order_id'
            );

        // An arbitrary, correctly signed order id
        // that isn't this checkout's.
        $this->verify(
            $pricey['checkout_id'],
            'order_someone_else'
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'razorpay_order_id'
            );

        $this->assertDatabaseCount(
            'orders',
            0
        );
    }

    public function test_repeated_successful_verification_is_idempotent(): void
    {
        $data = $this->checkout([
            $this->productItem($this->p1),
        ])->json('data');

        $first = $this->verify(
            $data['checkout_id'],
            $data['order_id'],
            'pay_1'
        )->assertCreated();

        $this->verify(
            $data['checkout_id'],
            $data['order_id'],
            'pay_1'
        )
            ->assertOk()
            ->assertJsonPath(
                'data.id',
                $first->json('data.id')
            );

        // A different payment against an already-paid checkout is refused.
        $this->verify(
            $data['checkout_id'],
            $data['order_id'],
            'pay_2'
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors(
                'razorpay_payment_id'
            );

        $this->assertDatabaseCount(
            'orders',
            1
        );

        $this->assertDatabaseCount(
            'order_items',
            1
        );

        $this->assertSame(
            'pay_1',
            Order::first()->razorpay_payment_id
        );
    }

    public function test_price_snapshots_do_not_change_after_product_or_combo_price_edits(): void
    {
        $order = $this->paidOrder([
            $this->comboItem([
                $this->p1->id,
                $this->p2->id,
            ]),
            $this->productItem($this->p3),
        ]);

        $this->actingAsToken(
            $this->superadmin()
        );

        $this->patchJson(
            "/api/admin/products/{$this->p3->id}",
            [
                'selling_price' => 100,
            ]
        )->assertOk();

        $this->patchJson(
            "/api/admin/combos/{$this->combo->id}",
            [
                'items' => [
                    [
                        'product_id' => $this->p1->id,
                        'price' => 1,
                    ],
                    [
                        'product_id' => $this->p2->id,
                        'price' => 2,
                    ],
                ],
            ]
        )->assertOk();

        $this->getJson(
            "/api/admin/orders/{$order->id}"
        )
            ->assertOk()
            ->assertJsonPath(
                'data.total',
                fn ($v) => (float) $v === 1800.0
            ) // 500 + 600 + 700
            ->assertJsonPath(
                'data.amount_paid',
                fn ($v) => (float) $v === 1800.0
            )
            ->assertJsonPath(
                'data.items.0.selected_products.0.price',
                fn ($v) => (float) $v === 500.0
            )
            ->assertJsonPath(
                'data.items.0.selected_products.1.price',
                fn ($v) => (float) $v === 600.0
            )
            ->assertJsonPath(
                'data.items.1.unit_price',
                fn ($v) => (float) $v === 700.0
            );
    }

    public function test_a_checkout_priced_before_a_price_change_is_fulfilled_at_the_paid_price(): void
    {
        $data = $this->checkout([
            $this->comboItem([
                $this->p1->id,
            ]),
        ])->json('data');

        $this->combo->items()
            ->where('product_id', $this->p1->id)
            ->update([
                'price' => 5,
            ]);

        $this->verify(
            $data['checkout_id'],
            $data['order_id']
        )
            ->assertCreated()
            ->assertJsonPath(
                'data.total',
                fn ($v) => (float) $v === 500.0
            )
            ->assertJsonPath(
                'data.amount_paid',
                fn ($v) => (float) $v === 500.0
            );
    }

    // --- Admin -------------------------------------------------------------

    public function test_admin_sees_paid_orders_and_advances_fulfilment_in_order(): void
    {
        $this->checkout([
            $this->productItem($this->p2),
        ]); // unpaid checkout — never an order

        $order = $this->paidOrder([
            $this->comboItem([
                $this->p1->id,
            ]),
        ]);

        $this->actingAsToken(
            $this->admin()
        );

        $this->getJson(
            '/api/admin/orders'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath(
                'data.0.phone',
                '9876543210'
            )
            ->assertJsonPath(
                'data.0.customer_name',
                'Asha Rao'
            )
            ->assertJsonPath(
                'data.0.payment_status',
                'paid'
            )
            ->assertJsonPath(
                'data.0.status',
                'confirmed'
            )
            ->assertJsonPath(
                'data.0.items.0.selected_products.0.name',
                'Pro-1'
            );

        // Dispatched is no longer a step, and Confirmed is where it starts.
        foreach (['dispatched', 'confirmed'] as $status) {
            $this->patchJson(
                "/api/admin/orders/{$order->id}/status",
                [
                    'status' => $status,
                ]
            )
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }

        foreach (
            ['delivered'] as $status
        ) {
            $this->patchJson(
                "/api/admin/orders/{$order->id}/status",
                [
                    'status' => $status,
                ]
            )
                ->assertOk()
                ->assertJsonPath(
                    'data.status',
                    $status
                )
                ->assertJsonPath(
                    'data.payment_status',
                    'paid'
                );
        }

        $this->patchJson(
            "/api/admin/orders/{$order->id}/status",
            [
                'status' => 'delivered',
            ]
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_payment_status_and_other_statuses_are_not_admin_actions(): void
    {
        $order = $this->paidOrder([
            $this->productItem($this->p1),
        ]);

        $this->actingAsToken(
            $this->superadmin()
        );

        foreach (
            ['paid', 'pending', 'confirmed', 'dispatched', 'cancelled'] as $status
        ) {
            $this->patchJson(
                "/api/admin/orders/{$order->id}/status",
                [
                    'status' => $status,
                ]
            )
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }

        $this->patchJson(
            "/api/admin/orders/{$order->id}/status",
            [
                'status' => 'delivered',
                'payment_status' => 'refunded',
            ]
        )->assertOk();

        $this->assertSame(
            [
                'delivered',
                'paid',
            ],
            [
                $order->fresh()->status,
                $order->fresh()->payment_status,
            ]
        );
    }

    public function test_order_status_updates_require_the_orders_manage_permission(): void
    {
        $order = $this->paidOrder([
            $this->productItem($this->p1),
        ]);

        $this->actingAsToken(
            $this->userWith([
                'orders.view',
            ])
        );

        $this->getJson(
            "/api/admin/orders/{$order->id}"
        )->assertOk();

        $this->patchJson(
            "/api/admin/orders/{$order->id}/status",
            [
                'status' => 'confirmed',
            ]
        )->assertForbidden();

        $this->actingAsToken(
            User::factory()->create([
                'type' => User::TYPE_CUSTOMER,
            ])
        );

        $this->getJson(
            '/api/admin/orders'
        )->assertForbidden();
    }

    public function test_product_tax_is_inside_the_selling_price_not_added_on_top(): void
    {
        $this->p1->update([
            'selling_price' => 1000,
            'tax_percent' => 5,
        ]);

        $data = $this->checkout([
            $this->productItem($this->p1),
        ])
            ->assertCreated()
            ->json('data');

        // The ₹1,000 selling price is what is charged — its 5% tax is already in it.
        $this->assertSame(100000, $data['amount']);
        $this->assertSame(1000.0, (float) $data['total']);
        $this->assertSame(1000.0, (float) $data['items'][0]['unit_price']);
        $this->assertSame(1000.0, (float) $data['items'][0]['line_total']);
    }

    public function test_a_products_tax_percent_does_not_change_what_is_charged(): void
    {
        $charged = [];

        foreach ([0, 5, 18, 100] as $percent) {
            $this->p1->update(['selling_price' => 1180, 'tax_percent' => $percent]);

            $charged[$percent] = $this->checkout([$this->productItem($this->p1, 3)])
                ->assertCreated()
                ->json('data.amount');
        }

        // 3 × ₹1,180, whatever the tax rate.
        $this->assertSame([0 => 354000, 5 => 354000, 18 => 354000, 100 => 354000], $charged);
    }

    public function test_products_and_combos_with_tax_are_charged_their_listed_prices_in_one_basket(): void
    {
        $this->p2->update(['selling_price' => 1000, 'tax_percent' => 5]);
        $this->combo->update(['tax_percent' => 5, 'bundle_price' => 1400]);

        $data = $this->checkout([
            $this->productItem($this->p2, 2),
            $this->comboItem([$this->p1->id, $this->p2->id, $this->p3->id]),
        ])
            ->assertCreated()
            ->json('data');

        // Product: 2 × ₹1,000. Combo: ₹1,400. The 5% tax is inside both prices.
        $this->assertSame(1000.0, (float) $data['items'][0]['unit_price']);
        $this->assertSame(2000.0, (float) $data['items'][0]['line_total']);
        $this->assertSame(1400.0, (float) $data['items'][1]['unit_price']);
        $this->assertSame(340000, $data['amount']);
    }

    public function test_a_combos_tax_percent_does_not_change_what_is_charged(): void
    {
        $charged = [];

        foreach ([0, 5, 18, 100] as $percent) {
            $this->combo->update(['tax_percent' => $percent, 'bundle_price' => 1400]);

            $charged[$percent] = $this->checkout([
                $this->comboItem([$this->p1->id, $this->p2->id, $this->p3->id], 2),
            ])
                ->assertCreated()
                ->json('data.amount');
        }

        // 2 × the ₹1,400 bundle, whatever the tax rate.
        $this->assertSame([0 => 280000, 5 => 280000, 18 => 280000, 100 => 280000], $charged);
    }

    public function test_partial_combo_tax_is_inside_the_selected_combo_price(): void
    {
        $this->combo->update([
            'tax_percent' => 5,
        ]);

        $data = $this->checkout([
            $this->comboItem([
                $this->p1->id,
                $this->p3->id,
            ]),
        ])
            ->assertCreated()
            ->json('data');

        // ₹500 + ₹450 = ₹950 — the 5% tax is already inside it, nothing is added.
        $this->assertSame(95000, $data['amount']);
        $this->assertSame(950.0, (float) $data['total']);
        $this->assertSame(950.0, (float) $data['items'][0]['unit_price']);
        $this->assertSame(950.0, (float) $data['items'][0]['line_total']);
    }

    public function test_complete_combo_bundle_price_includes_the_combo_tax(): void
    {
        $this->combo->update([
            'tax_percent' => 5,
            'bundle_price' => 1400,
        ]);

        $data = $this->checkout([
            $this->comboItem([
                $this->p1->id,
                $this->p2->id,
                $this->p3->id,
            ]),
        ])
            ->assertCreated()
            ->json('data');

        // Complete selection uses the ₹1,400 bundle price, 5% tax included in it.
        $this->assertSame(140000, $data['amount']);
        $this->assertSame(1400.0, (float) $data['total']);
        $this->assertSame(1400.0, (float) $data['items'][0]['unit_price']);
        $this->assertSame(1400.0, (float) $data['items'][0]['line_total']);
    }

    public function test_online_order_lines_snapshot_the_tax_inside_the_price_and_source_is_online(): void
    {
        $this->p1->update(['selling_price' => 1050, 'tax_percent' => 5]);

        $order = $this->paidOrder([$this->productItem($this->p1, 2)]);

        $this->assertSame(Order::SOURCE_ONLINE, $order->source);
        $this->assertNull($order->payment_method);
        $this->assertSame('2100.00', $order->total);

        $item = $order->items()->sole();
        $this->assertSame('5.00', $item->tax_percent);
        // 2100 × 5 / 105 — informational, the charge is still 2100.
        $this->assertSame('100.00', $item->tax_amount);
    }

    public function test_legacy_pending_and_dispatched_orders_can_still_be_marked_delivered(): void
    {
        $pending = $this->paidOrder([$this->productItem($this->p1)]);
        $dispatched = $this->paidOrder([$this->productItem($this->p2)]);
        $pending->forceFill(['status' => Order::STATUS_PENDING])->save();
        $dispatched->forceFill(['status' => Order::STATUS_DISPATCHED])->save();

        $this->actingAsToken($this->superadmin());

        foreach ([$pending, $dispatched] as $order) {
            $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'delivered'])
                ->assertOk()
                ->assertJsonPath('data.status', 'delivered');
        }
    }
}
