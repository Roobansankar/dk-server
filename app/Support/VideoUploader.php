<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Homepage video uploads: stores the file exactly as uploaded — no ffmpeg
 * transcoding, no generated poster frame. Delegates the generic disk
 * operations (url/delete) to ImageUploader — a stored path is a stored path
 * regardless of media type, and there's no reason to duplicate that.
 *
 * This used to transcode to a smaller H.264 MP4 via ffmpeg and generate a
 * WebP poster frame, but that required a working `ffmpeg`/`ffprobe` on the
 * server plus a PHP build that allows shell execution (proc_open) — neither
 * of which shared hosting reliably provides, and there's no code-level
 * workaround when they're missing. Uploads are capped by config('salon.
 * video_uploads.max_kb') (see StoreVideoRequest) instead of relying on
 * compression to keep files small; there's simply no thumbnail (thumbnail_
 * path is always null — VideoResource/the admin UI already treat a missing
 * thumbnail as a normal state, since generation was always best-effort).
 */
class VideoUploader
{
    public static function disk(): string
    {
        return ImageUploader::disk();
    }

    public static function url(?string $path): ?string
    {
        return ImageUploader::url($path);
    }

    public static function delete(?string $path): void
    {
        ImageUploader::delete($path);
    }

    /**
     * Store an uploaded video exactly as-is.
     *
     * @return array{video_path: string, thumbnail_path: null}
     */
    public static function store(UploadedFile $file, string $directory): array
    {
        $directory = trim($directory, '/');
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'mp4');
        $name = Str::uuid()->toString().'.'.$extension;

        $videoPath = $file->storeAs($directory, $name, ['disk' => self::disk()]);

        return ['video_path' => $videoPath, 'thumbnail_path' => null];
    }
}
