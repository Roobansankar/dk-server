<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOfflineBillRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Product;
use App\Services\ProductInventoryService;
use App\Support\ImageUploader;
use App\Support\OrderPricing;
use App\Support\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin → Offline Billing: a walk-in customer buys products in the studio and
 * pays on the spot (UPI / cash / card). The bill becomes a normal Order
 * (source = offline) so it shows up in Admin → Orders like any other, priced
 * by the same App\Support\OrderPricing and deducting stock through the same
 * ProductInventoryService::recordSale() as the online Razorpay checkout.
 * No Razorpay is involved.
 */
class OfflineBillingController extends Controller
{
    /** Every active product with its current stock — the bill's product picker. */
    public function products(Request $request): JsonResponse
    {
        $products = Product::query()
            ->active()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->get(['id', 'name', 'image_path', 'selling_price', 'tax_percent', 'stock_quantity']);

        return response()->json([
            'data' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'image_url' => ImageUploader::url($p->image_path),
                'price' => (float) $p->selling_price,
                'tax_percent' => (float) $p->tax_percent,
                'stock_quantity' => (int) $p->stock_quantity,
            ])->values(),
        ]);
    }

    /**
     * The server-priced bill summary for the current basket — lines, tax and
     * grand total — so the page never computes money itself. Also rejects
     * quantities above the current stock (422), without reserving anything.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(
            StoreOfflineBillRequest::itemRules(),
            (new StoreOfflineBillRequest)->messages()
        );

        return response()->json(['data' => $this->summary(OrderPricing::price($this->pricingItems($data['items'])))]);
    }

    public function store(StoreOfflineBillRequest $request, ProductInventoryService $inventory): JsonResponse
    {
        $data = $request->validated();

        // One transaction for the whole bill: the order, every line, every
        // stock deduction and SALE movement commit together or not at all.
        $order = DB::transaction(function () use ($data, $request, $inventory) {
            $priced = OrderPricing::price($this->pricingItems($data['items']));

            $order = new Order([
                'user_id' => null,
                'customer_name' => $data['customer_name'],
                // orders.phone is NOT NULL; a walk-in may give no number.
                'phone' => $data['phone'] ?? '',
                'subtotal' => $priced['subtotal'],
                'total' => $priced['subtotal'],
                // Collected in person, in full, before the bill is created.
                'amount_paid' => $priced['subtotal'],
                'payment_status' => Order::PAYMENT_PAID,
                'payment_method' => $data['payment_method'],
                'source' => Order::SOURCE_OFFLINE,
                'paid_at' => now(),
            ]);
            // Handed over at the counter — no phone verification step needed.
            $order->status = Order::STATUS_CONFIRMED;
            $order->save();

            foreach ($priced['lines'] as $line) {
                $order->items()->create(Arr::except($line, 'selected_products'));
            }

            // Locks each product row, re-checks stock authoritatively and
            // throws (rolling everything above back) if any line is short.
            $inventory->recordSale(
                array_map(fn ($line) => [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                ], $priced['lines']),
                $order,
                $request->user()->id
            );

            return $order;
        });

        // Paid in person just now → WhatsApp the customer their bill + alert
        // the owner, same as the online-checkout path. Best-effort: never
        // throws, and skips quietly (logged) when the walk-in gave no phone.
        try {
            DB::afterCommit(function () use ($order) {
                $fresh = $order->fresh();
                WhatsApp::sendOrderPaid($fresh);
                WhatsApp::sendOwnerOrderAlert($fresh);
            });
        } catch (\Throwable $e) {
            Log::warning('WhatsApp after offline bill skipped: '.$e->getMessage());
        }

        return (new OrderResource($order->load('items.selectedProducts')))
            ->additional(['message' => 'Offline bill created.'])
            ->response()
            ->setStatusCode(201);
    }

    /** The bill's rows in OrderPricing's item shape. */
    private function pricingItems(array $items): array
    {
        return array_map(fn ($item) => [
            'type' => 'product',
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
        ], array_values($items));
    }

    private function summary(array $priced): array
    {
        return [
            'items' => array_map(fn ($line) => [
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'unit_price' => (float) $line['unit_price'],
                'quantity' => $line['quantity'],
                'line_total' => (float) $line['line_total'],
                'tax_percent' => (float) $line['tax_percent'],
                'tax_amount' => (float) $line['tax_amount'],
            ], $priced['lines']),
            'subtotal' => (float) $priced['subtotal'],
            'tax_total' => (float) $priced['tax_total'],
            'total' => (float) $priced['subtotal'],
        ];
    }
}
