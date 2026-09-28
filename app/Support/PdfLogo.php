<?php

namespace App\Support;

/**
 * The DK StyleHub logo for the bill PDFs, embedded as a data URI so the PDF
 * never depends on a public URL, a file path or the PDF library's
 * remote-image setting, and so it renders the same on every machine. Two
 * versions, same as the site itself uses on light vs dark backgrounds:
 *  - dataUri() — the dark logo (resources/pdf/dk-stylehub-logo.png);
 *  - lightDataUri() — the light logo (resources/pdf/dk-stylehub-logo-light.png),
 *    the same asset the site's own dark footer uses, for the bill's dark header.
 */
class PdfLogo
{
    private static ?string $uri = null;

    private static ?string $lightUri = null;

    /** "data:image/png;base64,…", or '' if the file is missing (the bill then simply has no logo). */
    public static function dataUri(): string
    {
        return self::$uri ??= self::load('dk-stylehub-logo.png');
    }

    /** Same, but the light-on-dark version for a dark background. */
    public static function lightDataUri(): string
    {
        return self::$lightUri ??= self::load('dk-stylehub-logo-light.png');
    }

    private static function load(string $filename): string
    {
        $path = resource_path('pdf/'.$filename);
        $bytes = is_file($path) ? file_get_contents($path) : false;

        return $bytes === false ? '' : 'data:image/png;base64,'.base64_encode($bytes);
    }
}
