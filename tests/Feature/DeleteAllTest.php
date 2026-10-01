<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\DeleteAllRequest;
use App\Models\Appointment;
use App\Models\Combo;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProduct;
use App\Models\Product;
use App\Models\ProductCheckout;
use App\Models\ProductStockMovement;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\Stylist;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\CreatesAdmins;
use Tests\Concerns\CreatesBookableStylists;
use Tests\TestCase;

/**
 * The admin "Delete All" endpoints behind the Appointments, Appointment
 * History, Payment Report and Orders pages:
 *
 *   DELETE /api/admin/appointments  (Appointments + Appointment History)
 *   DELETE /api/admin/payments      (Payment Report)
 *   DELETE /api/admin/orders        (Orders)
 */
class DeleteAllTest extends TestCase
{
    use CreatesAdmins, CreatesBookableStylists, RefreshDatabase;

    private const ENDPOINTS = ['/api/admin/appointments', '/api/admin/payments', '/api/admin/orders'];

    private const CONFIRM = ['confirm' => DeleteAllRequest::CONFIRMATION];

    protected function setUp(): void
    {
        parent::setUp();

        // Distinct Razorpay order id per checkout, as in ProductOrderTest; no
        // real network call (WhatsApp is off in tests anyway).
        Http::fake([
            'api.razorpay.com/*' => fn (HttpRequest $request) => Http::response([
                'id' => 'order_'.$request->data()['receipt'],
                'entity' => 'order',
                'amount' => $request->data()['amount'],
                'currency' => 'INR',
                'status' => 'created',
            ], 200),
        ]);
    }

    private function deleteAll(string $endpoint, ?array $body = null): TestResponse
    {
        return $this->deleteJson($endpoint, $body ?? self::CONFIRM);
    }

    /** Make the next DELETE on `$table` blow up mid-transaction. */
    private function failWhenDeletingFrom(string $table): void
    {
        DB::listen(function (QueryExecuted $query) use ($table) {
            if (preg_match('/^delete from [`"]'.$table.'[`"]/i', $query->sql)) {
                throw new RuntimeException("Simulated failure deleting from {$table}.");
            }
        });
    }

    /** One appointment in every state the three appointment pages can show. */
    private function seedAppointments(): void
    {
        Appointment::factory()->pending()->create();
        Appointment::factory()->create(['status' => Appointment::STATUS_CONFIRMED]);
        Appointment::factory()->create(['status' => Appointment::STATUS_CONFIRMED, 'payment_status' => Appointment::PAYMENT_ADVANCE_PAID]);
        Appointment::factory()->create(['status' => Appointment::STATUS_COMPLETED, 'payment_status' => Appointment::PAYMENT_PAID]);
        Appointment::factory()->create(['status' => Appointment::STATUS_COMPLETED]);
        Appointment::factory()->offline()->create(['status' => Appointment::STATUS_CANCELLED]);
        Appointment::factory()->create(['status' => Appointment::STATUS_REJECTED]);
    }

    /** A bookable service with one professional — master data that must survive. */
    private function bookableService(): Service
    {
        $category = ServiceCategory::factory()->female()->create();
        $service = Service::factory()->forCategory($category)->create([
            'duration_minutes' => 60, 'price' => 2000, 'advance_percentage' => 25,
        ]);
        $this->bookableStylistFor($service);

        return $service;
    }

    /**
     * Two products, a combo of both, one online order (a product line and a
     * combo line) and one offline bill. Returns the two products.
     *
     * @return array{0: Product, 1: Product}
     */
    private function seedOrders(): array
    {
        $a = Product::factory()->create(['name' => 'Shampoo', 'selling_price' => 800, 'stock_quantity' => 20]);
        $b = Product::factory()->create(['name' => 'Serum', 'selling_price' => 600, 'stock_quantity' => 20]);

        $combo = Combo::create(['name' => 'Care Pack', 'slug' => 'care-pack', 'bundle_price' => 1000]);
        $combo->items()->create(['product_id' => $a->id, 'price' => 500, 'sort_order' => 0]);
        $combo->items()->create(['product_id' => $b->id, 'price' => 500, 'sort_order' => 1]);

        // Online: checkout + verified payment (public, no login).
        $checkout = $this->postJson('/api/checkout', [
            'customer_name' => 'Asha Rao',
            'phone' => '9876543210',
            'items' => [
                ['type' => 'product', 'product_id' => $a->id, 'quantity' => 2],
                ['type' => 'combo', 'combo_id' => $combo->id, 'product_ids' => [$a->id, $b->id], 'quantity' => 1],
            ],
        ])->assertCreated()->json('data');
        $this->verifyCheckout($checkout)->assertCreated();

        // Offline: a walk-in bill recorded by staff.
        $this->actingAsToken($this->userWith(['orders.view', 'orders.manage']));
        $this->postJson('/api/admin/offline-billing', [
            'items' => [['product_id' => $b->id, 'quantity' => 3]],
            'customer_name' => 'Walk-in Ravi',
            'phone' => '9876500000',
            'payment_method' => 'cash',
        ])->assertCreated();

        return [$a, $b];
    }

    private function verifyCheckout(array $checkout): TestResponse
    {
        return $this->postJson("/api/checkout/{$checkout['checkout_id']}/verify", [
            'razorpay_order_id' => $checkout['order_id'],
            'razorpay_payment_id' => 'pay_ok',
            'razorpay_signature' => hash_hmac('sha256', $checkout['order_id'].'|pay_ok', config('services.razorpay.secret')),
        ]);
    }

    // --- Authorization ----------------------------------------------------

    public function test_a_guest_gets_401_from_every_delete_all_endpoint(): void
    {
        $this->seedAppointments();

        foreach (self::ENDPOINTS as $endpoint) {
            $this->deleteAll($endpoint)->assertUnauthorized();
        }

        $this->assertSame(7, Appointment::count());
    }

    public function test_a_customer_account_is_forbidden_everywhere(): void
    {
        $this->seedAppointments();
        $this->seedRoles();
        $this->actingAsToken($this->customer());

        foreach (self::ENDPOINTS as $endpoint) {
            $this->deleteAll($endpoint)->assertForbidden();
        }

        $this->assertSame(7, Appointment::count());
    }

    public function test_staff_who_only_hold_the_view_permission_are_forbidden(): void
    {
        $this->seedAppointments();
        $this->actingAsToken($this->userWith(['appointments.view', 'payments.view', 'orders.view']));

        foreach (self::ENDPOINTS as $endpoint) {
            $this->deleteAll($endpoint)->assertForbidden();
        }

        $this->assertSame(7, Appointment::count());
    }

    /** The manage permission alone is not enough — Delete All is admin / superadmin only. */
    public function test_staff_with_the_manage_permission_but_no_admin_role_are_forbidden(): void
    {
        $this->seedAppointments();
        [$a] = $this->seedOrders();
        $this->actingAsToken($this->userWith(['appointments.manage', 'payments.view', 'orders.manage']));

        foreach (self::ENDPOINTS as $endpoint) {
            $this->deleteAll($endpoint)->assertForbidden();
        }

        $this->assertSame(7, Appointment::count());
        $this->assertSame(2, Order::count());
        $this->assertSame(17, $a->fresh()->stock_quantity);
    }

    /** The Payment Report deletes appointments, so it needs appointments.manage too. */
    public function test_an_admin_stripped_of_the_manage_permission_is_forbidden(): void
    {
        $this->seedAppointments();
        $admin = $this->admin();
        $admin->roles->first()->revokePermissionTo(['appointments.manage', 'orders.manage']);
        $this->actingAsToken($admin);

        foreach (self::ENDPOINTS as $endpoint) {
            $this->deleteAll($endpoint)->assertForbidden();
        }

        $this->assertSame(7, Appointment::count());
    }

    public function test_the_admin_role_can_use_every_delete_all_endpoint(): void
    {
        $this->seedAppointments();
        $this->seedOrders();
        $this->actingAsToken($this->admin());

        $this->deleteAll('/api/admin/payments')->assertOk()->assertJsonPath('deleted.appointments', 3);
        $this->deleteAll('/api/admin/appointments')->assertOk()->assertJsonPath('deleted.appointments', 4);
        $this->deleteAll('/api/admin/orders')->assertOk()->assertJsonPath('deleted.orders', 2);

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, Order::count());
    }

    public function test_a_superadmin_can_use_every_delete_all_endpoint(): void
    {
        $this->seedAppointments();
        $this->seedOrders();
        $this->actingAsToken($this->superadmin());

        $this->deleteAll('/api/admin/payments')->assertOk();
        $this->deleteAll('/api/admin/appointments')->assertOk();
        $this->deleteAll('/api/admin/orders')->assertOk();

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, Order::count());
    }

    // --- Confirmation -----------------------------------------------------

    /**
     * The admin UI only sends the phrase from its confirmation dialog's
     * "Delete All" button; a request without it must never delete anything.
     */
    public function test_nothing_is_deleted_without_the_confirmation_phrase(): void
    {
        $this->seedAppointments();
        $this->seedOrders();
        $this->actingAsToken($this->superadmin());

        foreach (self::ENDPOINTS as $endpoint) {
            $this->deleteAll($endpoint, [])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        }
        $this->deleteAll('/api/admin/appointments', ['confirm' => 'yes'])->assertUnprocessable();

        $this->assertSame(7, Appointment::count());
        $this->assertSame(2, Order::count());
    }

    // --- Appointments / Appointment History -------------------------------

    public function test_delete_all_appointments_removes_every_appointment_and_nothing_else(): void
    {
        $service = $this->bookableService();
        $this->seedAppointments();
        Appointment::factory()->forService($service)->forStylist(Stylist::first())->create();
        [$a] = $this->seedOrders();
        Review::factory()->create();
        $this->customer();
        $this->actingAsToken($this->superadmin());

        $before = [
            'users' => User::count(),
            'services' => Service::count(),
            'categories' => ServiceCategory::count(),
            'stylists' => Stylist::count(),
            'hours' => DB::table('stylist_date_hours')->count(),
            'settings' => SiteSetting::count(),
            'reviews' => Review::count(),
            'products' => Product::count(),
            'orders' => Order::count(),
            'movements' => ProductStockMovement::count(),
        ];

        $this->deleteAll('/api/admin/appointments')
            ->assertOk()
            ->assertJsonPath('deleted.appointments', 8)
            ->assertJsonPath('message', 'Deleted 8 appointments.');

        $this->assertSame(0, Appointment::count());
        $this->assertSame($before, [
            'users' => User::count(),
            'services' => Service::count(),
            'categories' => ServiceCategory::count(),
            'stylists' => Stylist::count(),
            'hours' => DB::table('stylist_date_hours')->count(),
            'settings' => SiteSetting::count(),
            'reviews' => Review::count(),
            'products' => Product::count(),
            'orders' => Order::count(),
            'movements' => ProductStockMovement::count(),
        ]);
        $this->assertSame(17, $a->fresh()->stock_quantity);
    }

    /** History is the same table: the list, the history view and the report all come back empty. */
    public function test_the_appointment_pages_and_the_payment_report_still_load_after_deletion(): void
    {
        $this->seedAppointments();
        $this->actingAsToken($this->superadmin());

        $this->deleteAll('/api/admin/appointments')->assertOk();

        $this->getJson('/api/admin/appointments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/appointments?sort=appointment_date&direction=asc&source=offline')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/admin/appointments/export')->assertOk();
        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.completed_appointments', 0)
            ->assertJsonPath('summary.total_service_value', fn ($v) => (float) $v === 0.0)
            ->assertJsonPath('summary.total_remaining', fn ($v) => (float) $v === 0.0);
        $this->get('/api/admin/payments/export')->assertOk();
        $this->getJson('/api/admin/dashboard')->assertOk();
    }

    public function test_deleting_appointments_when_there_are_none_is_safe(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->deleteAll('/api/admin/appointments')
            ->assertOk()
            ->assertJsonPath('deleted.appointments', 0)
            ->assertJsonPath('message', 'There were no appointments to delete.');
    }

    public function test_a_failure_while_deleting_appointments_rolls_everything_back(): void
    {
        $this->seedAppointments();
        $this->actingAsToken($this->superadmin());
        $this->failWhenDeletingFrom('appointments');

        $this->deleteAll('/api/admin/appointments')->assertStatus(500);

        $this->assertSame(7, Appointment::count());
    }

    public function test_public_booking_and_admin_confirmation_still_work_after_deletion(): void
    {
        $service = $this->bookableService();
        $stylist = Stylist::first();
        $date = now('Asia/Kolkata')->addDays(3)->toDateString();
        Appointment::factory()->forService($service)->forStylist($stylist)->create([
            'status' => Appointment::STATUS_CONFIRMED,
            'appointment_date' => $date,
            'appointment_time' => '15:00',
        ]);
        $admin = $this->actingAsToken($this->superadmin());

        $this->deleteAll('/api/admin/appointments')->assertOk();

        // The slot the deleted booking held is free again, and booking it works.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/appointments/busy?stylist_id={$stylist->id}&date={$date}")->assertOk()->assertJsonCount(0, 'data');

        $id = $this->postJson('/api/appointments', [
            'customer_name' => 'Priya R',
            'phone' => '+91 9790431212',
            'gender' => 'female',
            'category_id' => $service->service_category_id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
            'appointment_date' => $date,
            'appointment_time' => '15:00',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->actingAsToken($admin);
        $this->postJson("/api/admin/appointments/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame(1, Appointment::count());
    }

    // --- Payment Report ---------------------------------------------------

    public function test_delete_all_payments_removes_only_the_appointments_the_report_lists(): void
    {
        $this->seedAppointments();
        $this->actingAsToken($this->superadmin());

        $this->getJson('/api/admin/payments')->assertOk()->assertJsonCount(3, 'data');

        $this->deleteAll('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('deleted.appointments', 3);

        // Pending, confirmed-unpaid, cancelled and rejected never reached the report.
        $this->assertSame(4, Appointment::count());
        $this->assertSame(0, Appointment::query()->paidOrCompleted()->count());
        $this->assertEqualsCanonicalizing(
            ['pending', 'confirmed', 'cancelled', 'rejected'],
            Appointment::pluck('status')->all(),
        );

        // The report itself keeps working, now empty.
        $this->getJson('/api/admin/payments')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.completed_appointments', 0)
            ->assertJsonPath('summary.total_collected', fn ($v) => (float) $v === 0.0);
        $this->get('/api/admin/payments/export')->assertOk();
    }

    public function test_deleting_payments_ignores_the_report_filters_and_pagination(): void
    {
        Appointment::factory()->count(30)->create([
            'status' => Appointment::STATUS_COMPLETED,
            'payment_status' => Appointment::PAYMENT_PAID,
        ]);
        $this->actingAsToken($this->superadmin());

        $this->deleteJson('/api/admin/payments?per_page=5&page=2&payment_status=unpaid', self::CONFIRM)
            ->assertOk()
            ->assertJsonPath('deleted.appointments', 30);

        $this->assertSame(0, Appointment::count());
    }

    public function test_deleting_payments_when_there_are_none_is_safe(): void
    {
        Appointment::factory()->pending()->create();
        $this->actingAsToken($this->superadmin());

        $this->deleteAll('/api/admin/payments')
            ->assertOk()
            ->assertJsonPath('deleted.appointments', 0);

        $this->assertSame(1, Appointment::count());
    }

    public function test_a_failure_while_deleting_payments_rolls_everything_back(): void
    {
        $this->seedAppointments();
        $this->actingAsToken($this->superadmin());
        $this->failWhenDeletingFrom('appointments');

        $this->deleteAll('/api/admin/payments')->assertStatus(500);

        $this->assertSame(7, Appointment::count());
        $this->assertSame(3, Appointment::query()->paidOrCompleted()->count());
    }

    // --- Orders -----------------------------------------------------------

    public function test_delete_all_orders_removes_online_and_offline_orders_with_their_dependents(): void
    {
        [$a, $b] = $this->seedOrders();
        $this->seedAppointments();
        // A basket still waiting for payment is not an order and must survive.
        $pending = $this->postJson('/api/checkout', [
            'customer_name' => 'Mid Payment',
            'phone' => '9876511111',
            'items' => [['type' => 'product', 'product_id' => $a->id, 'quantity' => 1]],
        ])->assertCreated()->json('data');

        $this->assertSame(2, Order::count());
        $this->assertSame(3, OrderItem::count());
        $this->assertSame(2, OrderItemProduct::count());
        $this->assertSame(2, ProductCheckout::count());
        $movements = ProductStockMovement::count();
        $this->assertSame($movements, ProductStockMovement::whereNotNull('order_id')->count());

        $this->actingAsToken($this->superadmin());
        $this->deleteAll('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('deleted.orders', 2)
            ->assertJsonPath('deleted.order_items', 3)
            ->assertJsonPath('deleted.order_item_products', 2)
            ->assertJsonPath('deleted.product_checkouts', 1);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, OrderItemProduct::count());

        // Only the paid checkout went; the unpaid basket is untouched.
        $this->assertSame([$pending['checkout_id']], ProductCheckout::pluck('id')->all());

        // Unrelated data is untouched.
        $this->assertSame(2, Product::count());
        $this->assertSame(1, Combo::count());
        $this->assertSame(7, Appointment::count());
    }

    public function test_deleting_orders_never_restores_or_changes_stock_and_keeps_the_stock_history(): void
    {
        [$a, $b] = $this->seedOrders();
        // a: 20 − 2 (product line) − 1 (combo) = 17; b: 20 − 1 (combo) − 3 (offline) = 16.
        $this->assertSame(17, $a->fresh()->stock_quantity);
        $this->assertSame(16, $b->fresh()->stock_quantity);
        $history = ProductStockMovement::orderBy('id')->get(['id', 'product_id', 'type', 'quantity', 'balance_after', 'reason', 'updated_at'])->toArray();

        $this->actingAsToken($this->superadmin());
        $this->deleteAll('/api/admin/orders')->assertOk();

        $this->assertSame(17, $a->fresh()->stock_quantity);
        $this->assertSame(16, $b->fresh()->stock_quantity);

        // Every sale row survives exactly as it was; only the order link is cleared.
        $this->assertSame(
            $history,
            ProductStockMovement::orderBy('id')->get(['id', 'product_id', 'type', 'quantity', 'balance_after', 'reason', 'updated_at'])->toArray(),
        );
        $this->assertSame(0, ProductStockMovement::whereNotNull('order_id')->count());
        $this->assertStringStartsWith('Product order ORD-', ProductStockMovement::first()->reason);
    }

    /** With the paid checkout gone, replaying its verified payment cannot rebuild the order. */
    public function test_a_replayed_payment_cannot_recreate_a_deleted_order_or_deduct_stock_again(): void
    {
        $a = Product::factory()->create(['selling_price' => 800, 'stock_quantity' => 10]);
        $checkout = $this->postJson('/api/checkout', [
            'customer_name' => 'Asha Rao',
            'phone' => '9876543210',
            'items' => [['type' => 'product', 'product_id' => $a->id, 'quantity' => 2]],
        ])->assertCreated()->json('data');
        $this->verifyCheckout($checkout)->assertCreated();
        $this->assertSame(8, $a->fresh()->stock_quantity);

        $this->actingAsToken($this->superadmin());
        $this->deleteAll('/api/admin/orders')->assertOk();

        $this->verifyCheckout($checkout)->assertNotFound();

        $this->assertSame(0, Order::count());
        $this->assertSame(8, $a->fresh()->stock_quantity);
    }

    public function test_deleting_orders_when_there_are_none_is_safe(): void
    {
        $this->actingAsToken($this->superadmin());

        $this->deleteAll('/api/admin/orders')
            ->assertOk()
            ->assertJsonPath('deleted.orders', 0)
            ->assertJsonPath('message', 'There were no orders to delete.');
    }

    public function test_a_failure_while_deleting_orders_rolls_everything_back(): void
    {
        [$a, $b] = $this->seedOrders();
        $movements = ProductStockMovement::count();
        $this->actingAsToken($this->superadmin());
        // Fails on the last step — after the checkouts, items and stock links were already handled.
        $this->failWhenDeletingFrom('orders');

        $this->deleteAll('/api/admin/orders')->assertStatus(500);

        $this->assertSame(2, Order::count());
        $this->assertSame(3, OrderItem::count());
        $this->assertSame(2, OrderItemProduct::count());
        $this->assertSame(1, ProductCheckout::whereNotNull('order_id')->count());
        $this->assertSame($movements, ProductStockMovement::whereNotNull('order_id')->count());
        $this->assertSame(17, $a->fresh()->stock_quantity);
        $this->assertSame(16, $b->fresh()->stock_quantity);
    }

    public function test_orders_products_and_inventory_still_work_after_deletion(): void
    {
        [$a, $b] = $this->seedOrders();
        $this->actingAsToken($this->superadmin());
        $this->deleteAll('/api/admin/orders')->assertOk();

        // The order pages and inventory views load, empty or intact.
        $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/admin/orders/export')->assertOk();
        $this->getJson('/api/admin/inventory')->assertOk();
        $this->getJson('/api/admin/inventory/history')->assertOk();
        $this->getJson('/api/admin/products')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/products')->assertOk();

        // Stock can still be managed, and continues from the real balance.
        $this->postJson("/api/admin/inventory/products/{$a->id}/restock", ['quantity' => 5, 'reason' => 'Delivery'])->assertSuccessful();
        $this->assertSame(22, $a->fresh()->stock_quantity);

        // A new offline bill still creates an order and deducts stock.
        $this->postJson('/api/admin/offline-billing', [
            'items' => [['product_id' => $b->id, 'quantity' => 2]],
            'customer_name' => 'Walk-in Ravi',
            'phone' => '9876500000',
            'payment_method' => 'upi',
        ])->assertCreated();
        $this->assertSame(14, $b->fresh()->stock_quantity);

        // …and so does a new online order.
        $this->app['auth']->forgetGuards();
        $checkout = $this->postJson('/api/checkout', [
            'customer_name' => 'Asha Rao',
            'phone' => '9876543210',
            'items' => [['type' => 'product', 'product_id' => $a->id, 'quantity' => 1]],
        ])->assertCreated()->json('data');
        $this->verifyCheckout($checkout)->assertCreated();

        $this->assertSame(2, Order::count());
        $this->assertSame(21, $a->fresh()->stock_quantity);
    }
}
