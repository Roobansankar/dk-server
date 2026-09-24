<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * A customer's own (paid) product orders. Orders are never created here —
 * only by ProductCheckoutController::verify() after a verified payment.
 */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->forUser($request->user()->id)
            ->with('items.selectedProducts')
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order)
    {
        // 404, not 403, so a guessed id doesn't confirm the order exists.
        abort_unless($order->user_id === $request->user()->id, 404);

        return new OrderResource($order->load('items.selectedProducts'));
    }
}
