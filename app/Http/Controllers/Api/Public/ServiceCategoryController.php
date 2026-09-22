<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceCategoryController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'gender' => ['sometimes', Rule::in(ServiceCategory::GENDERS)],
            'with_services' => ['sometimes', 'boolean'],
        ]);

        $query = ServiceCategory::query()->active()->ordered();

        if (isset($validated['gender'])) {
            $query->gender($validated['gender']);
        }

        if ($request->boolean('with_services')) {
            $query->with(['services' => fn ($q) => $q->active()->ordered()]);
        } else {
            $query->withCount(['services' => fn ($q) => $q->active()]);
        }

        return ServiceCategoryResource::collection($query->get());
    }

    public function show(ServiceCategory $serviceCategory)
    {
        abort_unless($serviceCategory->status, 404);

        $serviceCategory->load(['services' => fn ($q) => $q->active()->ordered()]);

        return new ServiceCategoryResource($serviceCategory);
    }
}
