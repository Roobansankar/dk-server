<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreStylistRequest;
use App\Http\Requests\Admin\UpdateStylistRequest;
use App\Http\Resources\StylistResource;
use App\Models\Stylist;
use App\Support\ImageUploader;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StylistController extends Controller
{
    public function index(Request $request)
    {
        $stylists = Stylist::query()
            ->withCount('appointments')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->paginate($request->integer('per_page', 50));

        return StylistResource::collection($stylists);
    }

    public function store(StoreStylistRequest $request)
    {
        $data = $request->safe()->except(['image']);
        $data['slug'] = Slug::unique(Stylist::class, $request->string('name'));

        if ($request->hasFile('image')) {
            $data['image_path'] = ImageUploader::store($request->file('image'), 'stylists');
        }

        $stylist = Stylist::create($data);

        return (new StylistResource($stylist->loadCount('appointments')))
            ->response()->setStatusCode(201);
    }

    public function show(Stylist $stylist)
    {
        return new StylistResource($stylist->loadCount('appointments'));
    }

    public function update(UpdateStylistRequest $request, Stylist $stylist)
    {
        $data = $request->safe()->except(['image', 'remove_image']);

        if ($request->filled('name')) {
            $data['slug'] = Slug::unique(Stylist::class, $data['name'], 'slug', null, $stylist->id);
        }

        if ($request->hasFile('image')) {
            ImageUploader::delete($stylist->image_path);
            $data['image_path'] = ImageUploader::store($request->file('image'), 'stylists');
        } elseif ($request->boolean('remove_image')) {
            ImageUploader::delete($stylist->image_path);
            $data['image_path'] = null;
        }

        $stylist->update($data);

        return new StylistResource($stylist->loadCount('appointments'));
    }

    public function destroy(Stylist $stylist)
    {
        if ($stylist->appointments()->exists()) {
            return response()->json([
                'message' => 'This stylist is linked to appointments. Deactivate them instead to keep appointment history intact.',
            ], 422);
        }

        ImageUploader::delete($stylist->image_path);
        $stylist->delete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                Stylist::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
