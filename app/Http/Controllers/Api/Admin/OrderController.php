<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
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

    /** Fulfilment only (pending → confirmed → dispatched → delivered, or cancelled); payment status is separate. */
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
