<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A video upload failed for an environment/infrastructure reason (ffmpeg
 * missing, shell execution disabled, no usable H.264 encoder, unwritable
 * temp storage) — as opposed to App\Support\VideoUploader throwing a
 * ValidationException, which means the file itself was the problem. Kept
 * distinct from a bare RuntimeException so bootstrap/app.php can render it
 * as a specific, safe 503 message instead of Laravel's generic "Server
 * Error" — see the render() callback registered there.
 */
class VideoProcessingException extends RuntimeException {}
