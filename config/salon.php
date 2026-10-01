<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media storage disk
    |--------------------------------------------------------------------------
    |
    | Disk used for all admin-uploaded media (service category images, gallery
    | images, settings logo/favicon). Defaults to the local "public" disk so the
    | project runs with no extra setup; point MEDIA_DISK at "s3" (and fill the
    | AWS_* keys) to move uploads to cloud storage without touching code.
    |
    */

    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Upload constraints
    |--------------------------------------------------------------------------
    */

    'uploads' => [
        'max_kb' => (int) env('MEDIA_MAX_KB', 4096),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Video uploads (homepage Video section)
    |--------------------------------------------------------------------------
    |
    | `driver` picks how uploads are handled:
    |   - "ffmpeg" (default): transcodes/compresses every upload to H.264 via
    |     App\Support\VideoUploader. Needs a working ffmpeg + H.264 encoder on
    |     the server AND PHP shell execution (proc_open).
    |   - "direct": stores the file exactly as uploaded — no transcoding, no
    |     thumbnail, no shell calls at all. For shared hosting (e.g. Hostinger)
    |     where ffmpeg and/or proc_open are unavailable. Browsers show the
    |     first frame automatically (preload="metadata"), so no poster is
    |     needed. Keep uploads small yourself — nothing compresses them.
    | Set VIDEO_DRIVER=direct in .env on such hosts.
    |
    | `max_kb` is the application-level ceiling (default 25 MB) — larger
    | files are rejected with a validation message naming the limit (see
    | Store/UpdateVideoRequest). With the ffmpeg driver whatever comes through
    | under it is transcoded/compressed regardless of size, so there's no
    | fixed size below that this app needs to guard. The server's own PHP
    | upload_max_filesize/post_max_size must still be larger than max_kb (an
    | infrastructure setting, not an app one — see DEPLOYMENT.md), otherwise
    | PHP rejects the file before Laravel's validation runs. `mimes` is still
    | enforced. `ffmpeg_path` / `ffprobe_path` default to relying on $PATH;
    | override only if the binaries live somewhere non-standard.
    |
    */

    'video_uploads' => [
        'driver' => env('VIDEO_DRIVER', 'ffmpeg'),
        'mimes' => ['mp4', 'mov', 'webm', 'mkv', 'avi'],
        'max_kb' => (int) env('VIDEO_MAX_KB', 25600),
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap admin
    |--------------------------------------------------------------------------
    |
    | Used by AdminUserSeeder to create the first superadmin. Safe to run in
    | production; the seeder is skipped when these are not set.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Super Admin'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend origin
    |--------------------------------------------------------------------------
    |
    | Origin of the React SPA. Only used to build the redirect target after a
    | Google OAuth callback completes (see GoogleAuthController).
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

];
