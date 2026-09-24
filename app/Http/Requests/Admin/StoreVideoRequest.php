<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesVideoFile;
use Illuminate\Foundation\Http\FormRequest;

class StoreVideoRequest extends FormRequest
{
    use ValidatesVideoFile;

    public function authorize(): bool
    {
        return $this->user()->can('videos.manage');
    }

    public function rules(): array
    {
        return [
            // Videos are capped at config('salon.video_uploads.max_kb')
            // (default 25 MB) — anything larger is rejected here with a
            // message naming the limit. The server's own PHP
            // upload_max_filesize/post_max_size must stay above that cap, or
            // PHP rejects the file before this validation runs (named
            // specifically via withValidator() in Concerns\ValidatesVideoFile,
            // not from a rule here — see that trait's docblock for why it
            // has to happen there).
            'video' => [
                'required', 'file',
                // Same mechanism as image uploads (see ImageUploader/
                // StoreProductRequest etc.): Laravel's `mimes` rule checks
                // the file's actual detected content type, not the
                // client-supplied filename/extension.
                'mimes:'.implode(',', config('salon.video_uploads.mimes')),
                'max:'.config('salon.video_uploads.max_kb'),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        $mb = (int) round(config('salon.video_uploads.max_kb') / 1024);

        return [
            'video.max' => "The video must not be larger than {$mb} MB. Please compress it or choose a shorter clip.",
        ];
    }
}
