<?php

namespace App\Support;

use App\Exceptions\VideoProcessingException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Homepage video uploads: transcodes to a smaller, browser-compatible H.264
 * MP4 via ffmpeg before storing (real compression, not a renamed copy — see
 * store()), plus a best-effort WebP poster frame. Delegates the generic
 * disk operations (url/delete) to ImageUploader — a stored path is a stored
 * path regardless of media type, and there's no reason to duplicate that.
 *
 * Requires a working `ffmpeg` on the server (config('salon.video_uploads.
 * ffmpeg_path'), defaults to relying on $PATH) with a usable H.264 encoder,
 * AND a PHP build that allows shell execution (proc_open) — some shared
 * hosting plans disable it. If any of that isn't available, store() throws
 * VideoProcessingException with a specific, actionable message rather than
 * silently storing the uncompressed original under a false "compressed"
 * pretence.
 */
class VideoUploader
{
    /** Target video bitrate for the compressed output. */
    private const VIDEO_BITRATE = '1500k';

    private const VIDEO_MAXRATE = '1800k';

    private const VIDEO_BUFSIZE = '3000k';

    private const AUDIO_BITRATE = '128k';

    /** Never upscale — only ever shrinks a wider source down to this. */
    private const MAX_WIDTH = 1280;

    private const THUMB_MAX_WIDTH = 640;

    private const TRANSCODE_TIMEOUT = 180;

    private const THUMBNAIL_TIMEOUT = 30;

    /** H.264 encoders to try, in preference order — see pickEncoder(). */
    private const CANDIDATE_ENCODERS = ['libopenh264', 'libx264'];

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
     * Transcode + store an uploaded video. Returns the stored relative paths
     * — callers persist these, not a URL. No application-level size limit —
     * the Form Request only validates mime/extension (see config('salon.
     * video_uploads')) — this method transcodes whatever comes through,
     * shrinking it to the bitrate/width constants below regardless of the
     * original's size.
     *
     * @return array{video_path: string, thumbnail_path: ?string}
     *
     * @throws ValidationException the uploaded file itself couldn't be transcoded (likely not a real/valid video)
     * @throws VideoProcessingException the server environment can't process video right now (ffmpeg/proc_open/storage)
     */
    public static function store(UploadedFile $file, string $directory): array
    {
        self::assertShellExecutionAvailable();
        $ffmpeg = config('salon.video_uploads.ffmpeg_path');
        $ffprobe = config('salon.video_uploads.ffprobe_path');
        self::assertFfmpegAvailable($ffmpeg);
        self::assertFfprobeAvailable($ffprobe);
        $encoder = self::pickEncoder($ffmpeg);

        $directory = trim($directory, '/');
        $uuid = Str::uuid()->toString();
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir) && ! @mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
            throw new VideoProcessingException(
                "Video processing is unavailable: the server could not create its temp directory ({$tmpDir}). Check that storage/app is writable by the web server.",
            );
        }
        if (! is_writable($tmpDir)) {
            throw new VideoProcessingException(
                "Video processing is unavailable: {$tmpDir} is not writable by the web server. Fix its file permissions/ownership.",
            );
        }

        $outputTmp = $tmpDir.'/'.$uuid.'.mp4';
        $thumbTmp = $tmpDir.'/'.$uuid.'.webp';
        $sourcePath = $file->getRealPath();

        try {
            // Determine up front whether the source actually has an audio
            // stream, rather than always bolting on `-c:a aac` and trusting
            // ffmpeg's automatic "best stream" selection to do the right
            // thing — that heuristic is what let audio silently vanish for
            // some real-world uploads even though ffmpeg exited 0.
            $sourceHasAudio = self::hasAudioStream($ffprobe, $sourcePath);

            $command = [
                $ffmpeg, '-y',
                '-i', $sourcePath,
                '-map', '0:v:0',
                '-c:v', $encoder,
                '-b:v', self::VIDEO_BITRATE,
                '-maxrate', self::VIDEO_MAXRATE,
                '-bufsize', self::VIDEO_BUFSIZE,
                '-vf', "scale='min(".self::MAX_WIDTH.",iw)':-2",
            ];

            if ($sourceHasAudio) {
                // Explicit map (not automatic selection) + the native AAC-LC
                // encoder, which every mainstream browser supports.
                $command = array_merge($command, [
                    '-map', '0:a:0',
                    '-c:a', 'aac',
                    '-b:a', self::AUDIO_BITRATE,
                ]);
            } else {
                $command[] = '-an';
            }

            $command = array_merge($command, ['-movflags', '+faststart', $outputTmp]);

            $transcode = Process::timeout(self::TRANSCODE_TIMEOUT)->run($command);

            if (! $transcode->successful() || ! is_file($outputTmp) || filesize($outputTmp) === 0) {
                Log::warning('VideoUploader: transcode failed.', [
                    'exit_code' => $transcode->exitCode(),
                    'stderr' => $transcode->errorOutput(),
                    'encoder' => $encoder,
                ]);

                if (self::looksLikeEnvironmentFailure($transcode->errorOutput())) {
                    throw new VideoProcessingException(
                        "Video processing failed: ffmpeg could not run with encoder '{$encoder}' on this server (see logs for details). This usually means the server's ffmpeg build is missing codec support.",
                    );
                }

                throw ValidationException::withMessages([
                    'video' => 'That file could not be processed as a video. Please try a different file.',
                ]);
            }

            // Belt-and-braces: confirm the output actually carries audio
            // when the source did. ffmpeg can exit 0 while having silently
            // dropped an audio stream it couldn't decode/encode (e.g. an
            // exotic or corrupt codec) — never pass that off as a success.
            if ($sourceHasAudio && ! self::hasAudioStream($ffprobe, $outputTmp)) {
                Log::warning('VideoUploader: source had audio but the transcoded output does not.', [
                    'stderr' => $transcode->errorOutput(),
                    'encoder' => $encoder,
                ]);

                throw ValidationException::withMessages([
                    'video' => 'That file\'s audio track could not be processed. Please try a different file or re-export it with a standard audio codec.',
                ]);
            }

            $videoPath = $directory.'/'.$uuid.'.mp4';
            Storage::disk(self::disk())->put($videoPath, file_get_contents($outputTmp));

            $thumbnailPath = self::generateThumbnail($ffmpeg, $outputTmp, $thumbTmp, $directory, $uuid);

            return ['video_path' => $videoPath, 'thumbnail_path' => $thumbnailPath];
        } finally {
            self::cleanup([$outputTmp, $thumbTmp]);
        }
    }

    /**
     * Best-effort poster frame from the (already compressed) output — a
     * failure here never fails the upload, just leaves thumbnail_path null.
     */
    private static function generateThumbnail(
        string $ffmpeg,
        string $sourcePath,
        string $thumbTmp,
        string $directory,
        string $uuid,
    ): ?string {
        $thumb = Process::timeout(self::THUMBNAIL_TIMEOUT)->run([
            $ffmpeg, '-y',
            '-ss', '00:00:00.1',
            '-i', $sourcePath,
            '-frames:v', '1',
            '-vf', "scale='min(".self::THUMB_MAX_WIDTH.",iw)':-2",
            '-c:v', 'libwebp',
            $thumbTmp,
        ]);

        if (! $thumb->successful() || ! is_file($thumbTmp) || filesize($thumbTmp) === 0) {
            Log::warning('VideoUploader: thumbnail generation failed.', [
                'exit_code' => $thumb->exitCode(),
                'stderr' => $thumb->errorOutput(),
            ]);

            return null;
        }

        $thumbnailPath = $directory.'/'.$uuid.'-thumb.webp';
        Storage::disk(self::disk())->put($thumbnailPath, file_get_contents($thumbTmp));

        return $thumbnailPath;
    }

    private static function cleanup(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Laravel's Process facade shells out via Symfony Process, which requires
     * PHP's proc_open(). Some shared hosting plans disable proc_open/exec/
     * shell_exec in php.ini's disable_functions for tenant isolation — on
     * those plans no code-level fix is possible; the host must re-enable it
     * (or the account must move to a plan that allows shell execution).
     */
    private static function assertShellExecutionAvailable(): void
    {
        if (function_exists('proc_open') && ! self::isDisabled('proc_open')) {
            return;
        }

        Log::error('VideoUploader: proc_open() is unavailable — PHP shell execution is disabled on this server.');

        throw new VideoProcessingException(
            "Video processing is unavailable: this server's PHP configuration disables proc_open() (see disable_functions in php.ini). ".
            'Ask your hosting provider to enable proc_open/exec/shell_exec for this account — many shared hosting plans '.
            'block it by default and require a support request or a VPS/Cloud plan to allow it.',
        );
    }

    private static function isDisabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($function, $disabled, true);
    }

    private static function assertFfmpegAvailable(string $ffmpeg): void
    {
        try {
            $check = Process::timeout(10)->run([$ffmpeg, '-version']);
        } catch (\Throwable $e) {
            Log::error('VideoUploader: could not invoke ffmpeg.', ['error' => $e->getMessage()]);

            throw new VideoProcessingException(
                "Video processing is unavailable: the server could not run ffmpeg ({$e->getMessage()}).",
            );
        }

        if (! $check->successful()) {
            Log::error('VideoUploader: ffmpeg is not available on this server — video uploads cannot be processed.', [
                'ffmpeg_path' => $ffmpeg,
                'stderr' => $check->errorOutput(),
            ]);

            throw new VideoProcessingException(
                "Video processing is unavailable on this server: ffmpeg was not found at '{$ffmpeg}'. Install ffmpeg on the server ".
                'or set FFMPEG_PATH in .env to its full path.',
            );
        }
    }

    private static function assertFfprobeAvailable(string $ffprobe): void
    {
        try {
            $check = Process::timeout(10)->run([$ffprobe, '-version']);
        } catch (\Throwable $e) {
            Log::error('VideoUploader: could not invoke ffprobe.', ['error' => $e->getMessage()]);

            throw new VideoProcessingException(
                "Video processing is unavailable: the server could not run ffprobe ({$e->getMessage()}).",
            );
        }

        if (! $check->successful()) {
            Log::error('VideoUploader: ffprobe is not available on this server — video uploads cannot be processed.', [
                'ffprobe_path' => $ffprobe,
                'stderr' => $check->errorOutput(),
            ]);

            throw new VideoProcessingException(
                "Video processing is unavailable on this server: ffprobe was not found at '{$ffprobe}'. Install ffmpeg (which bundles ffprobe) on the server ".
                'or set FFPROBE_PATH in .env to its full path.',
            );
        }
    }

    /**
     * Whether the given media file has at least one audio stream — used
     * both to decide how to build the ffmpeg command (map + encode audio,
     * or `-an`) and, after transcoding, to confirm the output actually kept
     * the audio the source had.
     */
    private static function hasAudioStream(string $ffprobe, string $path): bool
    {
        $probe = Process::timeout(10)->run([
            $ffprobe, '-v', 'error',
            '-select_streams', 'a',
            '-show_entries', 'stream=index',
            '-of', 'csv=p=0',
            $path,
        ]);

        return $probe->successful() && trim($probe->output()) !== '';
    }

    /**
     * Picks the first candidate H.264 encoder ffmpeg actually reports as
     * built in (`ffmpeg -encoders`), instead of assuming a fixed one is
     * present — ffmpeg builds vary a lot across hosts (some ship libx264,
     * some ship libopenh264, some ship neither without extra packages).
     */
    private static function pickEncoder(string $ffmpeg): string
    {
        try {
            $result = Process::timeout(10)->run([$ffmpeg, '-hide_banner', '-encoders']);
        } catch (\Throwable $e) {
            $result = null;
        }

        $output = $result && $result->successful() ? $result->output() : '';

        foreach (self::CANDIDATE_ENCODERS as $encoder) {
            if (str_contains($output, $encoder)) {
                return $encoder;
            }
        }

        if ($output !== '') {
            Log::error('VideoUploader: no supported H.264 encoder found in this ffmpeg build.', [
                'checked' => self::CANDIDATE_ENCODERS,
            ]);

            throw new VideoProcessingException(
                "Video processing is unavailable: this server's ffmpeg build has no usable H.264 encoder (checked: ".
                implode(', ', self::CANDIDATE_ENCODERS).'). Install/enable an ffmpeg build with H.264 encoding support.',
            );
        }

        // Couldn't even list encoders (unexpected) — fall back to the
        // historical default and let the actual transcode attempt surface
        // the real error.
        return self::CANDIDATE_ENCODERS[0];
    }

    /**
     * Distinguishes "ffmpeg itself is broken/misconfigured" from "this file
     * is not a valid/supported video" so the former surfaces as a specific
     * 503 (VideoProcessingException) instead of masquerading as a 422 file
     * validation error.
     */
    private static function looksLikeEnvironmentFailure(string $stderr): bool
    {
        $needles = [
            'Unknown encoder',
            'Encoder not found',
            'not been compiled',
            'proc_open',
            'Permission denied',
            'No such file or directory',
        ];

        foreach ($needles as $needle) {
            if (str_contains($stderr, $needle)) {
                return true;
            }
        }

        return false;
    }
}
