<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\ImageUploader;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->paginate($request->integer('per_page', 25));

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        $data = $request->safe()->except(['image', 'is_featured']);
        $data['slug'] = Slug::unique(Product::class, $request->string('name'));

        if ($request->hasFile('image')) {
            $data['image_path'] = ImageUploader::store($request->file('image'), 'products');
        }

        $product = Product::create($data)->refresh();

        // Featured is never mass-assigned: route it through applyFeatured so the
        // one-featured-at-a-time rule is enforced (and locked) server-side.
        if ($request->boolean('is_featured')) {
            $product->applyFeatured(true);
        }

        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function show(Product $product)
    {
        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $data = $request->safe()->except(['image', 'remove_image', 'is_featured']);

        if ($request->filled('name')) {
            $data['slug'] = Slug::unique(Product::class, $data['name'], 'slug', null, $product->id);
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($product->image_path);
            $data['image_path'] = ImageUploader::store($request->file('image'), 'products');
        } elseif ($request->boolean('remove_image')) {
            ImageUploader::delete($product->image_path);
            $data['image_path'] = null;
        }

        $product->update($data);

        // Featured is applied separately so the exclusive rule is enforced (and
        // row-locked) server-side. Turning it off leaves nothing featured.
        if ($request->has('is_featured')) {
            $product->applyFeatured($request->boolean('is_featured'));
        }

        return new ProductResource($product->fresh());
    }

    public function destroy(Product $product)
    {
        ImageUploader::delete($product->image_path);
        $product->image_path = null;
        $product->saveQuietly();
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
