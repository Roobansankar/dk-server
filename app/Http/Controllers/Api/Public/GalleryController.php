<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\GalleryImageResource;
use App\Models\GalleryImage;

class GalleryController extends Controller
{
    public function index()
    {
        return GalleryImageResource::collection(
            GalleryImage::query()->active()->ordered()->get()
        );
    }
}
