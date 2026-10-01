<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteAllRequest;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProduct;
use App\Models\ProductCheckout;
use App\Models\ProductStockMovement;
use App\Support\BookingAvailability;
use App\Support\OrderBillPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = $this->filtered($request)
            ->with('items.selectedProducts')
            ->latest()
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return OrderResource::collection($orders);
    }

    /**
     * Download the current (optionally filtered) order list as an .xlsx
     * workbook — same filters as the list, same OpenSpout writer as the
     * payment report.
     */
    public function export(Request $request)
    {
        $orders = $this->filtered($request)
            ->with('items')
            ->latest('id');

        $path = tempnam(sys_get_temp_dir(), 'orders_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues([
            'Order Number', 'Date', 'Customer', 'Phone', 'Items',
            'Amount Paid', 'Payment Status', 'Fulfilment Status',
            'Source', 'Payment Method', 'Address',
        ]));

        foreach ($orders->lazy() as $o) {
            $writer->addRow(Row::fromValues([
                (string) $o->order_number,
                $o->created_at?->timezone(BookingAvailability::TZ)->format('Y-m-d H:i') ?? '',
                (string) $o->customer_name,
                (string) $o->phone,
                $o->items
                    ->map(fn ($i) => $i->quantity > 1 ? "{$i->name} × {$i->quantity}" : (string) $i->name)
                    ->implode(', '),
                (float) ($o->amount_paid ?? $o->total ?? 0),
                ucwords(str_replace('_', ' ', (string) $o->payment_status)),
                ucfirst((string) $o->status),
                ucfirst((string) $o->source),
                // Online orders are always paid through Razorpay.
                $o->payment_method ? strtoupper($o->payment_method) : ($o->razorpay_payment_id ? 'Razorpay' : ''),
                (string) $o->customer_address,
            ]));
        }
        $writer->close();

        return response()->download($path, 'dk-stylehub-orders-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load('items.selectedProducts'));
    }

    /**
     * Download a bill / invoice PDF for one order — same document WhatsApp
     * sends the customer on payment verification. Mirrors
     * AppointmentController::bill.
     */
    public function bill(Order $order)
    {
        return OrderBillPdf::make($order)->download(OrderBillPdf::filename($order));
    }

    /** Fulfilment only (confirmed → delivered); payment status is separate. */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order)
    {
        $status = $request->validated('status');

        $order = DB::transaction(function () use ($order, $status) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => "An order that is {$locked->status} cannot be marked {$status}.",
                ]);
            }

            $locked->status = $status;
            $locked->save();

            return $locked;
        });

        return new OrderResource($order->load('items.selectedProducts'));
    }

    /**
     * "Delete All" on Admin → Orders: permanently removes every product
     * order the page lists — online and offline bills alike, whatever the
     * filters / page currently on screen — with the rows an order owns:
     *
     *   - order_item_products → order_items → orders, children first;
     *   - the paid product_checkouts those orders were created from. Left
     *     behind (order_id nulled by the FK), a replayed payment verification
     *     would rebuild the order and deduct its stock a second time.
     *
     * Inventory is deliberately left alone. The stock was really sold, so
     * `products.stock_quantity` is not restored, and the sale rows in
     * product_stock_movements stay as stock history — only their link to the
     * vanished order is cleared (the order number survives in `reason`).
     * Products, combos and unpaid checkouts still mid-payment are untouched.
     *
     * One transaction, on the ids locked at the start: any failure rolls the
     * whole thing back, and an order placed while this runs is not caught up.
     */
    public function destroyAll(DeleteAllRequest $request)
    {
        $deleted = DB::transaction(function () {
            $deleted = ['orders' => 0, 'order_items' => 0, 'order_item_products' => 0, 'product_checkouts' => 0];

            $orderIds = Order::query()->lockForUpdate()->pluck('id');

            foreach ($orderIds->chunk(500) as $ids) {
                // Query-builder update: detaching must not bump updated_at on stock history.
                ProductStockMovement::query()->whereIn('order_id', $ids)->toBase()->update(['order_id' => null]);

                $deleted['product_checkouts'] += ProductCheckout::query()->whereIn('order_id', $ids)->delete();
                $deleted['order_item_products'] += OrderItemProduct::query()
                    ->whereIn('order_item_id', OrderItem::query()->select('id')->whereIn('order_id', $ids))
                    ->delete();
                $deleted['order_items'] += OrderItem::query()->whereIn('order_id', $ids)->delete();
                $deleted['orders'] += Order::query()->whereKey($ids)->delete();
            }

            return $deleted;
        });

        return response()->json([
            'message' => $deleted['orders'] === 0
                ? 'There were no orders to delete.'
                : "Deleted {$deleted['orders']} ".($deleted['orders'] === 1 ? 'order' : 'orders').'. Product stock was not changed.',
            'deleted' => $deleted,
        ]);
    }

    /** Validated list filters shared by index() and export(). */
    private function filtered(Request $request): Builder
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(Order::STATUSES)],
            'payment_status' => ['sometimes', 'nullable', Rule::in(Order::PAYMENT_STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return Order::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('order_number', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            });
    }
}
