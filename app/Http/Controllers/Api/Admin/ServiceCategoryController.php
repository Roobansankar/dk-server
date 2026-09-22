<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreServiceCategoryRequest;
use App\Http\Requests\Admin\UpdateServiceCategoryRequest;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Support\ImageUploader;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceCategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = ServiceCategory::query()
            ->withCount('services')
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->paginate($request->integer('per_page', 25));

        return ServiceCategoryResource::collection($categories);
    }

    public function store(StoreServiceCategoryRequest $request)
    {
        $data = $request->safe()->except(['image']);
        $data['slug'] = Slug::unique(ServiceCategory::class, $request->string('name'), 'slug',
            fn ($q) => $q->where('gender', $request->string('gender')));

        if ($request->hasFile('image')) {
            $data['image_path'] = ImageUploader::store($request->file('image'), 'service-categories');
        }

        $category = ServiceCategory::create($data);

        return (new ServiceCategoryResource($category->loadCount('services')))
            ->response()->setStatusCode(201);
    }

    public function show(ServiceCategory $serviceCategory)
    {
        return new ServiceCategoryResource(
            $serviceCategory->load(['services' => fn ($q) => $q->ordered()])->loadCount('services')
        );
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory)
    {
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->filled('name') || $request->filled('gender')) {
            $data['slug'] = Slug::unique(ServiceCategory::class, $data['name'] ?? $serviceCategory->name, 'slug',
                fn ($q) => $q->where('gender', $data['gender'] ?? $serviceCategory->gender), $serviceCategory->id);
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($serviceCategory->image_path);
            $data['image_path'] = ImageUploader::store($request->file('image'), 'service-categories');
        } elseif ($request->boolean('remove_image')) {
            ImageUploader::delete($serviceCategory->image_path);
            $data['image_path'] = null;
        }

        $serviceCategory->update($data);

        return new ServiceCategoryResource($serviceCategory->loadCount('services'));
    }

    public function destroy(ServiceCategory $serviceCategory)
    {
        if ($serviceCategory->services()->exists()) {
            return response()->json([
                'message' => 'This category still has services. Move or delete them first, or deactivate the category instead.',
            ], 422);
        }

        ImageUploader::delete($serviceCategory->image_path);
        $serviceCategory->delete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                ServiceCategory::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
