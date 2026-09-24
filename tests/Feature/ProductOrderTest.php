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
        $this->actingAsToken($this->customer());

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
        $this->actingAsToken($this->customer());

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
        $this->actingAsToken($this->customer());

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

    public function test_checkout_requires_a_signed_in_customer(): void
    {
        $items = [
            $this->productItem($this->p1),
        ];

        $this->checkout($items)
            ->assertUnauthorized();

        $this->actingAsToken($this->admin());

        $this->checkout($items)
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // --- Verification ------------------------------------------------------

    public function test_successful_payment_creates_exactly_one_paid_order_with_snapshots(): void
    {
        $customer = $this->actingAsToken($this->customer());

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
                $customer->id
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
                'pending'
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
        $this->actingAsToken($this->customer());

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
        $this->actingAsToken($this->customer());

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
        $this->actingAsToken($this->customer());

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
        $this->actingAsToken($this->customer());

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

    public function test_customer_cannot_verify_or_view_another_customers_checkout_or_order(): void
    {
        $owner = $this->actingAsToken($this->customer());

        $data = $this->checkout([
            $this->productItem($this->p1),
        ])->json('data');

        $this->actingAsToken(
            $this->customer()
        );

        $this->verify(
            $data['checkout_id'],
            $data['order_id']
        )->assertNotFound();

        $this->assertDatabaseCount(
            'orders',
            0
        );

        $this->actingAsToken($owner);

        $this->verify(
            $data['checkout_id'],
            $data['order_id']
        )->assertCreated();

        $order = Order::first();

        $this->getJson(
            "/api/account/orders/{$order->id}"
        )->assertOk();

        $this->getJson(
            '/api/account/orders'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsToken(
            $this->customer()
        );

        $this->getJson(
            "/api/account/orders/{$order->id}"
        )->assertNotFound();

        $this->getJson(
            '/api/account/orders'
        )
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_price_snapshots_do_not_change_after_product_or_combo_price_edits(): void
    {
        $this->actingAsToken(
            $this->customer()
        );

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
        $this->actingAsToken(
            $this->customer()
        );

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
        $this->actingAsToken(
            $this->customer()
        );

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
                'pending'
            )
            ->assertJsonPath(
                'data.0.items.0.selected_products.0.name',
                'Pro-1'
            );

        // Skipping ahead is refused.
        $this->patchJson(
            "/api/admin/orders/{$order->id}/status",
            [
                'status' => 'dispatched',
            ]
        )
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        foreach (
            ['confirmed', 'dispatched', 'delivered'] as $status
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
        $this->actingAsToken(
            $this->customer()
        );

        $order = $this->paidOrder([
            $this->productItem($this->p1),
        ]);

        $this->actingAsToken(
            $this->superadmin()
        );

        foreach (
            ['paid', 'pending', 'cancelled'] as $status
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
                'status' => 'confirmed',
                'payment_status' => 'refunded',
            ]
        )->assertOk();

        $this->assertSame(
            [
                'confirmed',
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
        $this->actingAsToken(
            $this->customer()
        );

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

    public function test_product_tax_is_added_to_the_server_calculated_price(): void
    {
        $this->actingAsToken($this->customer());

        $this->p1->update([
            'selling_price' => 1000,
            'tax_percent' => 5,
        ]);

        $data = $this->checkout([
            $this->productItem($this->p1),
        ])
            ->assertCreated()
            ->json('data');

        // ₹1,000 + 5% tax (₹50) = ₹1,050.
        $this->assertSame(105000, $data['amount']);
        $this->assertSame(1050.0, (float) $data['total']);
        $this->assertSame(1050.0, (float) $data['items'][0]['unit_price']);
        $this->assertSame(1050.0, (float) $data['items'][0]['line_total']);
    }

    public function test_partial_combo_tax_is_added_to_the_selected_combo_price(): void
    {
        $this->actingAsToken($this->customer());

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

        // ₹500 + ₹450 = ₹950.
        // ₹950 + 5% tax (₹47.50) = ₹997.50.
        $this->assertSame(99750, $data['amount']);
        $this->assertSame(997.50, (float) $data['total']);
        $this->assertSame(997.50, (float) $data['items'][0]['unit_price']);
        $this->assertSame(997.50, (float) $data['items'][0]['line_total']);
    }

    public function test_complete_combo_bundle_price_gets_the_combo_tax(): void
    {
        $this->actingAsToken($this->customer());

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

        // Complete selection uses the ₹1,400 bundle price.
        // ₹1,400 + 5% tax (₹70) = ₹1,470.
        $this->assertSame(147000, $data['amount']);
        $this->assertSame(1470.0, (float) $data['total']);
        $this->assertSame(1470.0, (float) $data['items'][0]['unit_price']);
        $this->assertSame(1470.0, (float) $data['items'][0]['line_total']);
    }
}
