<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;

class ProductController extends Controller
{
    /** Active retail products for the public /products shelf. */
    public function index()
    {
        return ProductResource::collection(
            Product::query()->active()->with('images')->ordered()->get()
        );
    }
}
