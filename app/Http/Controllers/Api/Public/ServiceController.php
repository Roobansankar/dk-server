<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'gender' => ['sometimes', Rule::in(ServiceCategory::GENDERS)],
            'category_id' => ['sometimes', 'integer'],
        ]);

        $query = Service::query()
            ->active()
            ->whereHas('category', fn ($q) => $q->active())
            ->with('category')
            ->ordered();

        if (isset($validated['category_id'])) {
            $query->where('service_category_id', $validated['category_id']);
        }

        if (isset($validated['gender'])) {
            $query->whereHas('category', fn ($q) => $q->where('gender', $validated['gender']));
        }

        return ServiceResource::collection($query->get());
    }

    public function show(Service $service)
    {
        abort_unless($service->status && $service->category && $service->category->status, 404);

        return new ServiceResource($service->load('category'));
    }
}
