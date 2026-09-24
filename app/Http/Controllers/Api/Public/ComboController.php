<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ComboResource;
use App\Models\Combo;

class ComboController extends Controller
{
    /** Active combos with their included products and combo-specific prices. */
    public function index()
    {
        return ComboResource::collection(
            Combo::query()->active()->with('items.product')->ordered()->get()
        );
    }
}
