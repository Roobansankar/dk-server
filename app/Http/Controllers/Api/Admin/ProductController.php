<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Services\ProductInventoryService;
use App\Support\GalleryImages;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with('images')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->withSum(
                ['stockMovements as items_sold' => fn ($q) => $q->where('type', ProductStockMovement::TYPE_SALE)],
                'quantity'
            )
            ->ordered()
            ->paginate($request->integer('per_page', 25));

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request, ProductInventoryService $inventory)
    {
        $data = $request->safe()->except(['image', 'images', 'image_order', 'is_featured', 'stock_quantity']);
        $data['slug'] = Slug::unique(Product::class, $request->string('name'));

        $product = Product::create($data)->refresh();

        // Up to four photos; the first becomes the cover (image_path).
        GalleryImages::apply($product, $request, 'products');

        // Featured is never mass-assigned: route it through applyFeatured so the
        // one-featured-at-a-time rule is enforced (and locked) server-side.
        if ($request->boolean('is_featured')) {
            $product->applyFeatured(true);
        }

        // Opening stock goes through the inventory ledger, not mass assignment.
        $this->syncStock($product, $request, $inventory, 'Opening stock');

        return (new ProductResource($product->fresh()->load('images')))->response()->setStatusCode(201);
    }

    public function show(Product $product)
    {
        return new ProductResource($product->load('images'));
    }

    public function update(UpdateProductRequest $request, Product $product, ProductInventoryService $inventory)
    {
        $data = $request->safe()->except(['image', 'images', 'image_order', 'remove_image', 'is_featured', 'stock_quantity']);

        if ($request->filled('name')) {
            $data['slug'] = Slug::unique(Product::class, $data['name'], 'slug', null, $product->id);
        }

        $product->update($data);

        // Add / remove / reorder photos (no photo fields sent = photos untouched).
        GalleryImages::apply($product, $request, 'products');

        // Featured is applied separately so the exclusive rule is enforced (and
        // row-locked) server-side. Turning it off leaves nothing featured.
        if ($request->has('is_featured')) {
            $product->applyFeatured($request->boolean('is_featured'));
        }

        $this->syncStock($product, $request, $inventory, 'Stock set from product form');

        return new ProductResource($product->fresh()->load('images'));
    }

    /**
     * Bring stock_quantity to the submitted "Stock Available" value by
     * recording the difference as a restock/adjustment movement, so every change
     * stays in the stock history.
     */
    private function syncStock(Product $product, Request $request, ProductInventoryService $inventory, string $reason): void
    {
        if (! $request->has('stock_quantity')) {
            return;
        }

        $delta = (int) $request->validated('stock_quantity') - (int) $product->fresh()->stock_quantity;

        if ($delta > 0) {
            $inventory->restock($product, $delta, $reason, $request->user()?->id);
        } elseif ($delta < 0) {
            $inventory->adjust($product, $delta, $reason, $request->user()?->id);
        }
    }

    public function destroy(Product $product)
    {
        GalleryImages::purge($product);
        $product->delete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                Product::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
