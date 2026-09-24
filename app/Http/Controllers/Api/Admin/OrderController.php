<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(Order::STATUSES)],
            'payment_status' => ['sometimes', 'nullable', Rule::in(Order::PAYMENT_STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $orders = Order::query()
            ->with('items.selectedProducts')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('order_number', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return OrderResource::collection($orders);
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load('items.selectedProducts'));
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
}
