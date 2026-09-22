<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\StoreVideoRequest;
use App\Http\Requests\Admin\UpdateVideoRequest;
use Tests\TestCase;

/**
 * Video uploads deliberately have no application-level size limit (see
 * config('salon.video_uploads') and both Form Requests' docblocks) — only
 * format is validated; App\Support\VideoUploader transcodes whatever comes
 * through regardless of size. This guards against that limit quietly coming
 * back, since a real oversized-file HTTP test would depend on the machine's
 * own php.ini (exactly the infrastructure setting this app must not enforce
 * a duplicate of).
 */
class VideoUploadValidationTest extends TestCase
{
    private function videoRule(array $rules): array
    {
        return array_filter($rules['video'], fn ($rule) => is_string($rule));
    }

    public function test_store_video_request_has_no_size_rule(): void
    {
        $rules = $this->videoRule((new StoreVideoRequest)->rules());

        $this->assertTrue(
            collect($rules)->contains(fn ($r) => str_starts_with($r, 'mimes:')),
            'Expected the mimes rule to still be enforced.',
        );
        $this->assertFalse(
            collect($rules)->contains(fn ($r) => str_starts_with($r, 'max:')),
            'Video upload must not have an application-level size limit.',
        );
    }

    public function test_update_video_request_has_no_size_rule(): void
    {
        $rules = $this->videoRule((new UpdateVideoRequest)->rules());

        $this->assertTrue(
            collect($rules)->contains(fn ($r) => str_starts_with($r, 'mimes:')),
            'Expected the mimes rule to still be enforced.',
        );
        $this->assertFalse(
            collect($rules)->contains(fn ($r) => str_starts_with($r, 'max:')),
            'Video upload must not have an application-level size limit.',
        );
    }

    public function test_video_uploads_config_has_no_max_kb(): void
    {
        $this->assertArrayNotHasKey('max_kb', config('salon.video_uploads'));
        $this->assertSame(['mp4', 'mov', 'webm', 'mkv', 'avi'], config('salon.video_uploads.mimes'));
    }
}
