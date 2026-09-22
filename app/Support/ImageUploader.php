<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Centralised handling for admin image uploads.
 *
 * - never trusts the original filename (generates a UUID name)
 * - transcodes JPEG/PNG uploads to WebP via GD before writing (smaller files,
 *   one consistent format on disk); already-WebP uploads pass through as-is
 * - writes through the configured media disk (local now, S3 later via env)
 * - returns the stored relative path; callers persist that, not a URL
 * - can delete a previous file when a record's image is replaced/removed
 */
class ImageUploader
{
    /** Re-encoded WebP quality — visually lossless-ish, well below source size. */
    private const WEBP_QUALITY = 82;

    public static function disk(): string
    {
        return config('salon.media_disk');
    }

    public static function store(UploadedFile $file, string $directory): string
    {
        $directory = trim($directory, '/');
        $mime = $file->getMimeType();

        // Only JPEG/PNG are worth transcoding; WebP already is one, and
        // anything else (shouldn't reach here past the `image` + `mimes`
        // validation rule) just falls through to the original-file path below.
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $webp = self::toWebp($file);
            if ($webp !== null) {
                $name = Str::uuid()->toString().'.webp';
                Storage::disk(self::disk())->put($directory.'/'.$name, $webp);

                return $directory.'/'.$name;
            }
            // Conversion failed (corrupt data, GD out of memory, …) — store the
            // original rather than losing the upload.
        }

        // Prefer the server-guessed extension (from the real MIME type) over the
        // client-supplied one; fall back to the client value only if guessing fails.
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $name = Str::uuid()->toString().'.'.$extension;

        return $file->storeAs($directory, $name, ['disk' => self::disk()]);
    }

    /** Decode the upload with GD and re-encode as WebP; null on any failure. */
    private static function toWebp(UploadedFile $file): ?string
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        try {
            $data = file_get_contents($file->getRealPath());
            $image = $data === false ? false : @imagecreatefromstring($data);

            if ($image === false) {
                return null;
            }

            // Flatten indexed PNGs to true colour and keep transparency intact
            // through the re-encode.
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);

            ob_start();
            $ok = imagewebp($image, null, self::WEBP_QUALITY);
            $webp = ob_get_clean();
            imagedestroy($image);

            return $ok && is_string($webp) && $webp !== '' ? $webp : null;
        } catch (\Throwable $e) {
            Log::warning('ImageUploader: WebP conversion failed, storing original.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function delete(?string $path): void
    {
        if ($path && Storage::disk(self::disk())->exists($path)) {
            Storage::disk(self::disk())->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk(self::disk())->url($path);
    }
}
