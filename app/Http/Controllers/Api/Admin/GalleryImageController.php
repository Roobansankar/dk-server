<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreGalleryImageRequest;
use App\Http\Requests\Admin\UpdateGalleryImageRequest;
use App\Http\Resources\GalleryImageResource;
use App\Models\GalleryImage;
use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GalleryImageController extends Controller
{
    public function index(Request $request)
    {
        $images = GalleryImage::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->ordered()
            ->paginate($request->integer('per_page', 30));

        return GalleryImageResource::collection($images);
    }

    public function store(StoreGalleryImageRequest $request)
    {
        $data = $request->safe()->except(['image']);
        $data['image_path'] = ImageUploader::store($request->file('image'), 'gallery');

        $image = GalleryImage::create($data);

        return (new GalleryImageResource($image))->response()->setStatusCode(201);
    }

    public function show(GalleryImage $gallery)
    {
        return new GalleryImageResource($gallery);
    }

    public function update(UpdateGalleryImageRequest $request, GalleryImage $gallery)
    {
        $data = $request->safe()->except(['image']);

        if ($request->hasFile('image')) {
            ImageUploader::delete($gallery->image_path);
            $data['image_path'] = ImageUploader::store($request->file('image'), 'gallery');
        }

        $gallery->update($data);

        return new GalleryImageResource($gallery);
    }

    public function destroy(GalleryImage $gallery)
    {
        ImageUploader::delete($gallery->image_path);
        $gallery->forceDelete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                GalleryImage::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
