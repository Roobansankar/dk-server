<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PricingPlanResource;
use App\Models\PricingPlan;

class PricingPlanController extends Controller
{
    public function index()
    {
        return PricingPlanResource::collection(
            PricingPlan::query()->active()->ordered()->get()
        );
    }
}
