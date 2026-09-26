<?php

namespace App\Support;

/**
 * The DK StyleHub logo for the bill PDFs — the same dark logo the website's
 * navbar shows on light backgrounds, as a transparent PNG
 * (resources/pdf/dk-stylehub-logo.png). Embedded as a data URI so the PDF never
 * depends on a public URL, a file path or the PDF library's remote-image
 * setting, and so it renders the same on every machine.
 */
class PdfLogo
{
    private static ?string $uri = null;

    /** "data:image/png;base64,…", or '' if the file is missing (the bill then simply has no logo). */
    public static function dataUri(): string
    {
        if (self::$uri !== null) {
            return self::$uri;
        }

        $path = resource_path('pdf/dk-stylehub-logo.png');
        $bytes = is_file($path) ? file_get_contents($path) : false;

        return self::$uri = $bytes === false ? '' : 'data:image/png;base64,'.base64_encode($bytes);
    }
}
