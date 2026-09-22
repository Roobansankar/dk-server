<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\StylistResource;
use App\Models\Stylist;

class StylistController extends Controller
{
    public function index()
    {
        return StylistResource::collection(
            Stylist::query()->active()->ordered()->get()
        );
    }
}
