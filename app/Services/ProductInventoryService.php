<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductInventoryService
{
    /**
     * Add stock to a product.
     */
    public function restock(
        Product $product,
        int $quantity,
        ?string $reason = null,
        ?int $userId = null
    ): Product {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Restock quantity must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($product, $quantity, $reason, $userId) {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->id);

            $newBalance = $lockedProduct->stock_quantity + $quantity;

            // stock_quantity is deliberately not mass-assignable.
            $lockedProduct->forceFill(['stock_quantity' => $newBalance])->save();

            ProductStockMovement::create([
                'product_id' => $lockedProduct->id,
                'type' => ProductStockMovement::TYPE_RESTOCK,
                'quantity' => $quantity,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'created_by' => $userId,
            ]);

            return $lockedProduct->fresh();
        });
    }

    /**
     * Adjust stock by a positive or negative quantity.
     */
    public function adjust(
        Product $product,
        int $quantity,
        ?string $reason = null,
        ?int $userId = null
    ): Product {
        if ($quantity === 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Adjustment quantity cannot be zero.',
            ]);
        }

        return DB::transaction(function () use ($product, $quantity, $reason, $userId) {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->id);

            $newBalance = $lockedProduct->stock_quantity + $quantity;

            if ($newBalance < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Stock cannot be reduced below zero.',
                ]);
            }

            // stock_quantity is deliberately not mass-assignable.
            $lockedProduct->forceFill(['stock_quantity' => $newBalance])->save();

            ProductStockMovement::create([
                'product_id' => $lockedProduct->id,
                'type' => ProductStockMovement::TYPE_ADJUSTMENT,
                'quantity' => $quantity,
                'balance_after' => $newBalance,
                'reason' => $reason,
                'created_by' => $userId,
            ]);

            return $lockedProduct->fresh();
        });
    }

    /**
     * Remove stock because of a completed product order.
     *
     * $items must contain:
     * [
     *     [
     *         'product_id' => 123,
     *         'quantity' => 2,
     *     ],
     * ]
     *
     * If the same product appears more than once, quantities are combined.
     */
    public function recordSale(
        array $items,
        Order $order,
        ?int $userId = null
    ): void {
        $requirements = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];

            if ($productId <= 0 || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'Invalid product inventory quantity.',
                ]);
            }

            $requirements[$productId] = ($requirements[$productId] ?? 0) + $quantity;
        }

        if ($requirements === []) {
            return;
        }

        ksort($requirements);

        foreach ($requirements as $productId => $quantity) {
            $product = Product::query()
                ->lockForUpdate()
                ->find($productId);

            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => "Product {$productId} no longer exists.",
                ]);
            }

            if ($product->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'items' => "Insufficient stock for {$product->name}. Available: {$product->stock_quantity}, required: {$quantity}.",
                ]);
            }

            $newBalance = $product->stock_quantity - $quantity;

            // stock_quantity is deliberately not mass-assignable.
            $product->forceFill(['stock_quantity' => $newBalance])->save();

            ProductStockMovement::create([
                'product_id' => $product->id,
                'type' => ProductStockMovement::TYPE_SALE,
                'quantity' => -$quantity,
                'balance_after' => $newBalance,
                'reason' => 'Product order ' . $order->order_number,
                'order_id' => $order->id,
                'created_by' => $userId,
            ]);
        }
    }
}
