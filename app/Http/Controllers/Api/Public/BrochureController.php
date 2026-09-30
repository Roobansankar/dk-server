<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Support\StudioBrochurePdf;

/**
 * The studio brochure behind the QR code on the public Contact page: all
 * active stylists (photo or initial placeholder) with the services each of
 * them offers. Public, read-only and always generated live from the current
 * catalogue.
 */
class BrochureController extends Controller
{
    public function show()
    {
        return StudioBrochurePdf::make()->download(StudioBrochurePdf::filename());
    }
}
