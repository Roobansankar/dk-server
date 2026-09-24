<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\StoreVideoRequest;
use App\Http\Requests\Admin\UpdateVideoRequest;
use Tests\TestCase;

/**
 * Video uploads are capped at config('salon.video_uploads.max_kb')
 * (default 25 MB) — format AND size are validated; larger files are
 * rejected with a message naming the limit. This guards against the cap
 * quietly disappearing, since an oversized-file HTTP test would depend on
 * the machine's own php.ini (which must separately stay above the cap —
 * exactly the infrastructure setting this app must not duplicate below it).
 */
class VideoUploadValidationTest extends TestCase
{
    private function videoRule(array $rules): array
    {
        return array_filter($rules['video'], fn ($rule) => is_string($rule));
    }

    public function test_store_video_request_has_size_rule(): void
    {
        $rules = $this->videoRule((new StoreVideoRequest)->rules());

        $this->assertTrue(
            collect($rules)->contains(fn ($r) => str_starts_with($r, 'mimes:')),
            'Expected the mimes rule to still be enforced.',
        );
        $this->assertContains(
            'max:'.config('salon.video_uploads.max_kb'),
            $rules,
            'Video upload must enforce the application-level size limit.',
        );
        $this->assertSame(25600, config('salon.video_uploads.max_kb'));
    }

    public function test_update_video_request_has_size_rule(): void
    {
        $rules = $this->videoRule((new UpdateVideoRequest)->rules());

        $this->assertTrue(
            collect($rules)->contains(fn ($r) => str_starts_with($r, 'mimes:')),
            'Expected the mimes rule to still be enforced.',
        );
        $this->assertContains(
            'max:'.config('salon.video_uploads.max_kb'),
            $rules,
            'Video upload must enforce the application-level size limit.',
        );
    }

    public function test_video_uploads_config_has_max_kb(): void
    {
        $this->assertArrayHasKey('max_kb', config('salon.video_uploads'));
        $this->assertSame(['mp4', 'mov', 'webm', 'mkv', 'avi'], config('salon.video_uploads.mimes'));
    }

    public function test_video_max_message_names_the_limit(): void
    {
        $messages = (new StoreVideoRequest)->messages();

        $this->assertArrayHasKey('video.max', $messages);
        $this->assertStringContainsString('25 MB', $messages['video.max']);
    }
}
