<?php

namespace App\Support;

use App\Models\Combo;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

class OrderPricing
{
    /**
     * Backward-compatible pricing entry point used by checkout.
     *
     * The checkout controller still expects the original price()
     * response structure: lines + subtotal.
     */
    public static function price(array $items): array
    {
        $calculated = self::calculate($items);

        $lines = array_map(
            static function (array $line): array {
                return [
                    'item_type' => $line['combo_id'] !== null ? 'combo' : 'product',
                    'product_id' => $line['product_id'],
                    'combo_id' => $line['combo_id'],
                    'name' => $line['name'],
                    'unit_price' => self::toRupees($line['unit_paise']),
                    'quantity' => $line['quantity'],
                    'line_total' => self::toRupees($line['line_total_paise']),
                    'selected_products' => $line['selected_products'],
                ];
            },
            $calculated['items']
        );

        return [
            'lines' => $lines,
            'subtotal' => self::toRupees($calculated['subtotal_paise']),
        ];
    }

    /**
     * Calculate authoritative checkout pricing.
     *
     * Client-supplied prices are never trusted.
     */
    public static function calculate(array $items): array
    {
        $lines = [];
        $subtotalPaise = 0;

        foreach ($items as $index => $item) {
            $type = $item['type'] ?? null;
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity < 1) {
                self::fail(
                    "items.$index.quantity",
                    'Quantity must be at least 1.'
                );
            }

            if ($type === 'product') {
                $line = self::productLine(
                    $index,
                    (int) ($item['product_id'] ?? 0)
                );
            } elseif ($type === 'combo') {
                $line = self::comboLine(
                    $index,
                    (int) ($item['combo_id'] ?? 0),
                    $item['product_ids'] ?? []
                );
            } else {
                self::fail(
                    "items.$index.type",
                    'Invalid item type.'
                );
            }

            $line['quantity'] = $quantity;
            $line['line_total_paise'] = $line['unit_paise'] * $quantity;

            $subtotalPaise += $line['line_total_paise'];
            $lines[] = $line;
        }

        self::assertStockAvailable($items, $lines);

        return [
            'items' => $lines,
            'subtotal_paise' => $subtotalPaise,
            'total_paise' => $subtotalPaise,
        ];
    }

    private static function productLine(
        int $index,
        int $productId
    ): array {
        $product = Product::query()
            ->active()
            ->find($productId);

        if (! $product) {
            self::fail(
                "items.$index.product_id",
                'This product is not available.'
            );
        }

        $basePaise = self::toPaise($product->selling_price);
        $unitPaise = self::addTax(
            $basePaise,
            (float) $product->tax_percent
        );

        return [
            'product_id' => $product->id,
            'combo_id' => null,
            'name' => $product->name,
            'unit_paise' => $unitPaise,
            'selected_products' => [],
        ];
    }

    private static function comboLine(
        int $index,
        int $comboId,
        array $productIds
    ): array {
        $combo = Combo::query()
            ->active()
            ->with('items')
            ->find($comboId);

        if (! $combo) {
            self::fail(
                "items.$index.combo_id",
                'This combo is not available.'
            );
        }

        $productIds = array_values(
            array_unique(
                array_map('intval', $productIds)
            )
        );

        if ($productIds === []) {
            self::fail(
                "items.$index.product_ids",
                'Select at least one product from the combo.'
            );
        }

        $configured = $combo->items->keyBy('product_id');

        $unknown = array_diff(
            $productIds,
            $configured->keys()->all()
        );

        if ($unknown !== []) {
            self::fail(
                "items.$index.product_ids",
                'One or more selected products are not part of this combo.'
            );
        }

        $products = Product::query()
            ->active()
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            self::fail(
                "items.$index.product_ids",
                'One or more selected products are no longer available.'
            );
        }

        // Keep the admin-configured combo order for the snapshot.
        $selected = [];
        $individualUnitPaise = 0;

        foreach ($configured as $productId => $comboItem) {
            if (! $products->has($productId)) {
                continue;
            }

            $paise = self::toPaise($comboItem->price);
            $individualUnitPaise += $paise;

            $selected[] = [
                'product_id' => $productId,
                'product_name' => $products[$productId]->name,
                'price' => self::toRupees($paise),
            ];
        }

        /*
         * Bundle pricing:
         *
         * - All configured products selected + bundle price configured:
         *   use the bundle price.
         *
         * - Proper subset selected:
         *   sum the selected combo-specific prices.
         *
         * - All products selected but no bundle price configured:
         *   fall back to the sum of the individual combo prices.
         */
        $allProductsSelected = count($selected) === $configured->count();

        $baseUnitPaise = $individualUnitPaise;

        if (
            $allProductsSelected
            && $combo->bundle_price !== null
        ) {
            $baseUnitPaise = self::toPaise($combo->bundle_price);
        }

        $unitPaise = self::addTax(
            $baseUnitPaise,
            (float) $combo->tax_percent
        );

        return [
            'product_id' => null,
            'combo_id' => $combo->id,
            'name' => $combo->name,
            'unit_paise' => $unitPaise,
            'selected_products' => $selected,
        ];
    }

    /**
     * Fail fast when the basket asks for more units than are in stock.
     *
     * The atomic check in ProductInventoryService::recordSale() remains the
     * authority at payment verification time; this pre-check rejects the
     * oversell at POST /api/checkout instead — before any Razorpay order or
     * payment exists.
     */
    private static function assertStockAvailable(array $items, array $lines): void
    {
        $required = [];
        $firstIndex = [];

        foreach ($lines as $index => $line) {
            $quantity = (int) $line['quantity'];

            if ($line['combo_id'] !== null) {
                foreach ($line['selected_products'] as $selected) {
                    $productId = (int) $selected['product_id'];
                    $required[$productId] = ($required[$productId] ?? 0) + $quantity;
                    $firstIndex[$productId] ??= $index;
                }

                continue;
            }

            if ($line['product_id'] !== null) {
                $productId = (int) $line['product_id'];
                $required[$productId] = ($required[$productId] ?? 0) + $quantity;
                $firstIndex[$productId] ??= $index;
            }
        }

        if ($required === []) {
            return;
        }

        $products = Product::query()
            ->whereIn('id', array_keys($required))
            ->get()
            ->keyBy('id');

        foreach ($required as $productId => $quantity) {
            $product = $products->get($productId);
            $index = $firstIndex[$productId] ?? 0;

            if (! $product || ! $product->status) {
                continue;
            }

            $available = (int) $product->stock_quantity;

            if ($available < $quantity) {
                self::fail(
                    "items.$index.quantity",
                    $available <= 0
                        ? "“{$product->name}” is out of stock."
                        : "Only {$available} of “{$product->name}” available."
                );
            }
        }
    }

    /**
     * Add a percentage tax to a base amount in paise.
     */
    private static function addTax(
        int $basePaise,
        float $taxPercent
    ): int {
        if ($basePaise <= 0 || $taxPercent <= 0) {
            return $basePaise;
        }

        return $basePaise + (int) round(
            $basePaise * ($taxPercent / 100)
        );
    }

    private static function toPaise(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function toRupees(int $paise): string
    {
        return number_format(
            $paise / 100,
            2,
            '.',
            ''
        );
    }

    private static function fail(
        string $key,
        string $message
    ): never {
        throw ValidationException::withMessages([
            $key => $message,
        ]);
    }
}
