<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustProductStockRequest;
use App\Http\Requests\RestockProductRequest;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Services\ProductInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductInventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when(
                $request->filled('search'),
                fn ($query) => $query->where(function ($query) use ($request) {
                    $search = $request->string('search')->trim()->toString();

                    $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                })
            )
            ->orderBy('name')
            ->paginate(
                min((int) $request->input('per_page', 20), 100)
            );

        return response()->json($products);
    }

    public function history(Request $request): JsonResponse
    {
        $movements = ProductStockMovement::query()
            ->with([
                'product:id,name',
                'order:id,order_number',
                'createdBy:id,name',
            ])
            ->when(
                $request->filled('product_id'),
                fn ($query) => $query->where(
                    'product_id',
                    (int) $request->input('product_id')
                )
            )
            ->when(
                $request->filled('type'),
                fn ($query) => $query->where(
                    'type',
                    $request->string('type')->toString()
                )
            )
            ->latest()
            ->paginate(
                min((int) $request->input('per_page', 30), 100)
            );

        return response()->json($movements);
    }

    public function restock(
        RestockProductRequest $request,
        Product $product,
        ProductInventoryService $inventory
    ): JsonResponse {
        $updated = $inventory->restock(
            $product,
            (int) $request->validated('quantity'),
            $request->validated('reason'),
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Product stock restocked successfully.',
            'product' => $updated,
        ]);
    }

    public function adjust(
        AdjustProductStockRequest $request,
        Product $product,
        ProductInventoryService $inventory
    ): JsonResponse {
        $updated = $inventory->adjust(
            $product,
            (int) $request->validated('quantity'),
            $request->validated('reason'),
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Product stock adjusted successfully.',
            'product' => $updated,
        ]);
    }
}
