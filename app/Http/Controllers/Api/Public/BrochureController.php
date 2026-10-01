<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Support\StudioBrochurePdf;
use Illuminate\Http\Request;

/**
 * The studio brochure behind the QR code on the public Contact page: all
 * active stylists (photo or initial placeholder) with the services each of
 * them offers. Public, read-only and always generated live from the current
 * catalogue.
 *
 * Inline by default — scanning the QR (or opening this URL directly) opens
 * the PDF in the browser/an embedding <iframe> instead of silently starting
 * a download; the frontend /brochure page is what the QR actually points
 * at, with its own explicit "Download" button that adds ?download=1 here.
 */
class BrochureController extends Controller
{
    public function show(Request $request)
    {
        $pdf = StudioBrochurePdf::make();
        $filename = StudioBrochurePdf::filename();

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }
}
