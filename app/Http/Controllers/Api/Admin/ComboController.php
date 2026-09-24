<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreComboRequest;
use App\Http\Requests\Admin\UpdateComboRequest;
use App\Http\Resources\ComboResource;
use App\Models\Combo;
use App\Support\ImageUploader;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComboController extends Controller
{
    public function index(Request $request)
    {
        $combos = Combo::query()
            ->with('items.product')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->paginate($request->integer('per_page', 25));

        return ComboResource::collection($combos);
    }

    public function store(StoreComboRequest $request)
    {
        $data = $request->safe()->except(['image', 'items']);
        $data['slug'] = Slug::unique(Combo::class, $request->string('name'));

        if ($request->hasFile('image')) {
            $data['image_path'] = ImageUploader::store($request->file('image'), 'combos');
        }

        $combo = DB::transaction(function () use ($data, $request) {
            $combo = Combo::create($data);
            $this->syncItems($combo, $request->validated('items'));

            return $combo;
        });

        return (new ComboResource($combo->fresh()->load('items.product')))
            ->response()->setStatusCode(201);
    }

    public function show(Combo $combo)
    {
        return new ComboResource($combo->load('items.product'));
    }

    public function update(UpdateComboRequest $request, Combo $combo)
    {
        $data = $request->safe()->except(['image', 'remove_image', 'items']);

        if ($request->filled('name')) {
            $data['slug'] = Slug::unique(Combo::class, $data['name'], 'slug', null, $combo->id);
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($combo->image_path);
            $data['image_path'] = ImageUploader::store($request->file('image'), 'combos');
        } elseif ($request->boolean('remove_image')) {
            ImageUploader::delete($combo->image_path);
            $data['image_path'] = null;
        }

        DB::transaction(function () use ($combo, $data, $request) {
            $combo->update($data);

            if ($request->has('items')) {
                $this->syncItems($combo, $request->validated('items'));
            }
        });

        return new ComboResource($combo->fresh()->load('items.product'));
    }

    public function destroy(Combo $combo)
    {
        ImageUploader::delete($combo->image_path);
        $combo->image_path = null;
        $combo->saveQuietly();
        $combo->delete();

        return response()->noContent();
    }

    /**
     * Replace the combo's product list. Only the combo configuration changes —
     * past orders keep their own price snapshots (order_item_products).
     */
    private function syncItems(Combo $combo, array $items): void
    {
        $combo->items()->delete();

        foreach (array_values($items) as $position => $item) {
            $combo->items()->create([
                'product_id' => $item['product_id'],
                'price' => $item['price'],
                'sort_order' => $position,
            ]);
        }
    }
}
