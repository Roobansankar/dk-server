<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $services = Service::query()
            ->with('category')
            ->when($request->filled('category_id'), fn ($q) => $q->where('service_category_id', $request->integer('category_id')))
            ->when($request->filled('gender'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('gender', $request->string('gender'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->paginate($request->integer('per_page', 25));

        return ServiceResource::collection($services);
    }

    public function store(StoreServiceRequest $request)
    {
        $data = $request->validated();
        $data['slug'] = Slug::unique(Service::class, $data['name'], 'slug',
            fn ($q) => $q->where('service_category_id', $data['service_category_id']));

        $service = Service::create($data);

        return (new ServiceResource($service->load('category')))->response()->setStatusCode(201);
    }

    public function show(Service $service)
    {
        return new ServiceResource($service->load('category'));
    }

    public function update(UpdateServiceRequest $request, Service $service)
    {
        $data = $request->validated();

        if (isset($data['name']) || isset($data['service_category_id'])) {
            $data['slug'] = Slug::unique(Service::class, $data['name'] ?? $service->name, 'slug',
                fn ($q) => $q->where('service_category_id', $data['service_category_id'] ?? $service->service_category_id),
                $service->id);
        }

        $service->update($data);

        return new ServiceResource($service->load('category'));
    }

    public function destroy(Request $request, Service $service)
    {
        // Soft delete keeps appointment history intact via the nullable FK.
        $service->delete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                Service::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
