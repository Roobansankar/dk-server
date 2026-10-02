<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Support\StudioBrochurePdf;
use Illuminate\Http\Request;

/**
 * The studio brochure behind the QR code on the public Home/Contact pages
 * and the admin Settings print QR: all active stylists (photo or initial
 * placeholder) with the services each of them offers. Public, read-only and
 * always generated live from the current catalogue.
 *
 * Inline by default — scanning the QR (or opening this URL directly) opens
 * the PDF straight in the phone's own viewer instead of silently starting a
 * download. ?download=1 forces a real "Save as" download instead.
 */
class BrochureController extends Controller
{
    public function show(Request $request)
    {
        $pdf = StudioBrochurePdf::make();
        $filename = StudioBrochurePdf::filename();

        if ($request->boolean('download')) {
            return $pdf->download($filename);
        }

        // Dompdf's stream() (unlike download()) sends no Content-Length, so
        // phones that need the total size up front to render every page of
        // an inline PDF (rather than just the first) were stopping after
        // page one — the download, which has the header, always opened in
        // full. Setting it here fixes that without touching the vendor file.
        $response = $pdf->stream($filename);
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));

        return $response;
    }
}
