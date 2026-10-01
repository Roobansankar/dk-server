<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class OfflineBillingTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    private Product $a;

    private Product $b;

    private Product $c;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->a = Product::factory()->create(['name' => 'Product A', 'selling_price' => 1200, 'tax_percent' => 5, 'stock_quantity' => 5]);
        $this->b = Product::factory()->create(['name' => 'Product B', 'selling_price' => 800, 'tax_percent' => 0, 'stock_quantity' => 4]);
        $this->c = Product::factory()->create(['name' => 'Product C', 'selling_price' => 350, 'tax_percent' => 18, 'stock_quantity' => 10]);

        $this->staff = $this->actingAsToken($this->userWith(['orders.view', 'orders.manage']));
    }

    private function bill(array $items, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/admin/offline-billing', array_merge([
            'items' => $items,
            'customer_name' => 'Walk-in Ravi',
            'phone' => '9876543210',
            'payment_method' => 'cash',
        ], $overrides));
    }

    private function line(Product $product, int $quantity): array
    {
        return ['product_id' => $product->id, 'quantity' => $quantity];
    }

    public function test_one_bill_with_several_products_creates_one_paid_confirmed_offline_order(): void
    {
        $response = $this->bill([
            $this->line($this->a, 2),
            $this->line($this->b, 1),
            $this->line($this->c, 3),
        ])->assertCreated()
            ->assertJsonPath('data.source', 'offline')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payment_method', 'cash')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.phone', '9876543210')
            ->assertJsonCount(3, 'data.items');

        // 2 × 1200 + 1 × 800 + 3 × 350 = 4250 — tax is inside the prices.
        $this->assertEquals(4250, $response->json('data.total'));
        $this->assertEquals(4250, $response->json('data.amount_paid'));

        $order = Order::sole();
        $this->assertNull($order->user_id);
        $this->assertNull($order->razorpay_order_id);
        $this->assertNotNull($order->paid_at);

        $this->assertSame(3, $this->a->fresh()->stock_quantity);
        $this->assertSame(3, $this->b->fresh()->stock_quantity);
        $this->assertSame(7, $this->c->fresh()->stock_quantity);

        $movements = ProductStockMovement::where('type', ProductStockMovement::TYPE_SALE)->orderBy('product_id')->get();
        $this->assertCount(3, $movements);
        $this->assertEquals(
            [[$this->a->id, -2, 3], [$this->b->id, -1, 3], [$this->c->id, -3, 7]],
            $movements->map(fn ($m) => [$m->product_id, $m->quantity, $m->balance_after])->all()
        );
        $this->assertTrue($movements->every(fn ($m) => $m->order_id === $order->id && $m->created_by === $this->staff->id));
    }

    public function test_line_snapshots_include_price_and_included_tax_and_survive_product_edits(): void
    {
        $this->bill([$this->line($this->a, 2)])->assertCreated();

        $this->a->update(['name' => 'Renamed', 'selling_price' => 9999, 'tax_percent' => 28]);

        $item = Order::sole()->items()->sole();
        $this->assertSame('Product A', $item->name);
        $this->assertSame('1200.00', $item->unit_price);
        $this->assertSame('2400.00', $item->line_total);
        $this->assertSame('5.00', $item->tax_percent);
        // 2400 × 5 / 105
        $this->assertSame('114.29', $item->tax_amount);
    }

    public function test_upi_cash_and_card_are_each_recorded_as_paid(): void
    {
        foreach (['upi', 'cash', 'card'] as $method) {
            $this->bill([$this->line($this->c, 1)], ['payment_method' => $method])
                ->assertCreated()
                ->assertJsonPath('data.payment_method', $method)
                ->assertJsonPath('data.payment_status', 'paid');
        }

        $this->bill([$this->line($this->c, 1)], ['payment_method' => 'razorpay'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(3, Order::count());
    }

    public function test_quantity_above_stock_is_rejected_and_nothing_changes(): void
    {
        $this->bill([$this->line($this->b, 5)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.quantity');

        $this->assertSame(0, Order::count());
        $this->assertSame(4, $this->b->fresh()->stock_quantity);
        $this->assertSame(0, ProductStockMovement::count());
    }

    public function test_out_of_stock_product_cannot_be_billed(): void
    {
        $this->b->forceFill(['stock_quantity' => 0])->save();

        $this->getJson('/api/admin/offline-billing/products')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->b->id, 'stock_quantity' => 0]);

        $this->bill([$this->line($this->b, 1)])
            ->assertUnprocessable()
            ->assertJsonFragment(['“Product B” is out of stock.']);

        $this->assertSame(0, Order::count());
    }

    public function test_one_short_line_fails_the_whole_multi_product_bill(): void
    {
        $this->bill([
            $this->line($this->a, 2),
            $this->line($this->b, 1),
            $this->line($this->c, 11), // only 10 in stock
        ])->assertUnprocessable();

        $this->assertSame(0, Order::count());
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(0, ProductStockMovement::count());
        $this->assertSame(5, $this->a->fresh()->stock_quantity);
        $this->assertSame(4, $this->b->fresh()->stock_quantity);
        $this->assertSame(10, $this->c->fresh()->stock_quantity);
    }

    public function test_stock_taken_between_pricing_and_deduction_rolls_the_whole_bill_back(): void
    {
        // Pricing passes (C has 10), then another sale takes C's stock before
        // recordSale() locks the row — the authoritative locked check must
        // roll back the already-created order, its lines and A's deduction.
        Product::updated(function (Product $p) {
            if ($p->id === $this->a->id) {
                Product::whereKey($this->c->id)->update(['stock_quantity' => 1]);
            }
        });

        $this->bill([
            $this->line($this->a, 2),
            $this->line($this->c, 3),
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        Product::flushEventListeners();

        $this->assertSame(0, Order::count());
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame(0, ProductStockMovement::count());
        $this->assertSame(5, $this->a->fresh()->stock_quantity);
    }

    public function test_duplicate_product_rows_are_rejected(): void
    {
        $this->bill([$this->line($this->a, 1), $this->line($this->a, 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.1.product_id');
    }

    public function test_name_and_payment_method_are_required_but_phone_is_optional(): void
    {
        $this->postJson('/api/admin/offline-billing', ['items' => [$this->line($this->a, 1)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name', 'payment_method'])
            ->assertJsonMissingValidationErrors(['phone']);

        $this->bill([$this->line($this->a, 1)], ['phone' => null])
            ->assertCreated()
            ->assertJsonPath('data.phone', '')
            ->assertJsonPath('data.customer_address', null);

        $this->bill([$this->line($this->a, 1)], ['phone' => 'not-a-phone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_preview_returns_server_priced_lines_tax_and_total_without_changing_anything(): void
    {
        $this->postJson('/api/admin/offline-billing/preview', [
            'items' => [$this->line($this->a, 2), $this->line($this->c, 1)],
        ])->assertOk()
            ->assertJsonPath('data.items.0.unit_price', fn ($v) => (float) $v === 1200.0)
            ->assertJsonPath('data.items.0.line_total', fn ($v) => (float) $v === 2400.0)
            // 114.29 + 350 × 18 / 118 = 114.29 + 53.39
            ->assertJsonPath('data.tax_total', fn ($v) => (float) $v === 167.68)
            ->assertJsonPath('data.total', fn ($v) => (float) $v === 2750.0);

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $this->a->fresh()->stock_quantity);
    }

    public function test_offline_orders_show_in_the_orders_list_search_and_status_flow(): void
    {
        $id = $this->bill([$this->line($this->a, 1)])->json('data.id');

        $this->getJson('/api/admin/orders?search=Walk-in')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.source', 'offline');

        $this->getJson('/api/admin/orders?status=confirmed')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/admin/orders?status=pending')->assertJsonCount(0, 'data');

        $this->getJson("/api/admin/orders/{$id}")->assertOk()->assertJsonPath('data.phone', '9876543210');

        $this->patchJson("/api/admin/orders/{$id}/status", ['status' => 'dispatched'])
            ->assertUnprocessable();

        $this->patchJson("/api/admin/orders/{$id}/status", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->get('/api/admin/orders/export')->assertOk();
    }

    public function test_offline_billing_requires_orders_manage(): void
    {
        $this->actingAsToken($this->userWith(['orders.view']));

        $this->getJson('/api/admin/offline-billing/products')->assertForbidden();
        $this->bill([$this->line($this->a, 1)])->assertForbidden();

        // A legacy customer row carries no staff permission either.
        $this->actingAsToken($this->customer());
        $this->bill([$this->line($this->a, 1)])->assertForbidden();

        $this->assertSame(0, Order::count());
    }

    public function test_offline_billing_never_calls_razorpay(): void
    {
        $this->bill([$this->line($this->a, 1)])->assertCreated();

        Http::assertNothingSent();
    }
}
