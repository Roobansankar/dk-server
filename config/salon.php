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
    | No application-level size cap by design — the original upload is
    | transcoded/compressed by App\Support\VideoUploader regardless of how
    | large it is, so there's no fixed size this app needs to guard. The
    | server's own PHP upload_max_filesize/post_max_size still applies (an
    | infrastructure setting, not an app one — see DEPLOYMENT.md). `mimes`
    | is still enforced. `ffmpeg_path` / `ffprobe_path` default to relying on
    | $PATH; override only if the binaries live somewhere non-standard.
    |
    */

    'video_uploads' => [
        'mimes' => ['mp4', 'mov', 'webm', 'mkv', 'avi'],
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
