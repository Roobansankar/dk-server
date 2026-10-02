<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Support\StudioBrochurePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The studio brochure behind the QR code on the public Home/Contact pages
 * and the admin Settings print QR: all active stylists (photo or initial
 * placeholder) with the services each of them offers. Public, read-only.
 *
 * Inline by default — scanning the QR (or opening this URL directly) opens
 * the PDF straight in the phone's own viewer instead of silently starting a
 * download. ?download=1 forces a real "Save as" download instead.
 */
class BrochureController extends Controller
{
    /** Minutes a rendered PDF is reused before the next request rebuilds it. */
    private const CACHE_MINUTES = 5;

    private const CACHE_PATH = 'cache/studio-brochure.pdf';

    public function show(Request $request)
    {
        $filename = StudioBrochurePdf::filename();
        $bytes = $this->cachedBytes();

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
        ];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.$filename.'"';

            return response($bytes, 200, $headers);
        }

        $headers['Content-Disposition'] = 'inline; filename="'.$filename.'"';
        // Belt-and-braces alongside the caching above: tells any proxy/CDN
        // in front of this response (Hostinger's does this on its own) not
        // to split it into Range sub-requests at all.
        $headers['Accept-Ranges'] = 'none';

        return response($bytes, 200, $headers);
    }

    /**
     * The rendered PDF, reused for CACHE_MINUTES rather than rebuilt on
     * every request: dompdf embeds a render timestamp, so two independent
     * renders are never byte-identical even though both are valid on their
     * own. A phone reading a large inline PDF in more than one HTTP request
     * (the start to show page one, then a Range request for the rest) was
     * landing on two *different* renders — same size, different bytes —
     * which corrupted the document from the viewer's side after page one.
     * A shared file means every request in the window returns the exact
     * same bytes, so a split read reassembles cleanly.
     *
     * A plain cached file rather than the `cache` table: the database cache
     * driver stores values in a text column, and MySQL rejects a raw binary
     * PDF there as invalid UTF-8 (regression test for that).
     */
    private function cachedBytes(): string
    {
        $disk = Storage::disk('local');
        $fresh = $disk->exists(self::CACHE_PATH)
            && $disk->lastModified(self::CACHE_PATH) > now()->subMinutes(self::CACHE_MINUTES)->timestamp;

        if ($fresh) {
            return $disk->get(self::CACHE_PATH);
        }

        $bytes = StudioBrochurePdf::make()->output();
        $disk->put(self::CACHE_PATH, $bytes);

        return $bytes;
    }
}
