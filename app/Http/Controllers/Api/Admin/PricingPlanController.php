<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StorePricingPlanRequest;
use App\Http\Requests\Admin\UpdatePricingPlanRequest;
use App\Http\Resources\PricingPlanResource;
use App\Models\PricingPlan;
use App\Support\Slug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PricingPlanController extends Controller
{
    public function index(Request $request)
    {
        $plans = PricingPlan::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->ordered()
            ->paginate($request->integer('per_page', 25));

        return PricingPlanResource::collection($plans);
    }

    public function store(StorePricingPlanRequest $request)
    {
        $data = $request->validated();
        $data['slug'] = Slug::unique(PricingPlan::class, $data['name']);

        $plan = PricingPlan::create($data);

        return (new PricingPlanResource($plan))->response()->setStatusCode(201);
    }

    public function show(PricingPlan $pricingPlan)
    {
        return new PricingPlanResource($pricingPlan);
    }

    public function update(UpdatePricingPlanRequest $request, PricingPlan $pricingPlan)
    {
        $data = $request->validated();

        if (isset($data['name'])) {
            $data['slug'] = Slug::unique(PricingPlan::class, $data['name'], 'slug', null, $pricingPlan->id);
        }

        $pricingPlan->update($data);

        return new PricingPlanResource($pricingPlan);
    }

    public function destroy(PricingPlan $pricingPlan)
    {
        $pricingPlan->delete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                PricingPlan::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
