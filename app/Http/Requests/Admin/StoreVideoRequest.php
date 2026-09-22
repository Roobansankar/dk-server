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
            // No application-level size cap by design (see config('salon.
            // video_uploads')'s docblock) — VideoUploader transcodes whatever
            // comes through regardless of original size. Only the server's
            // own PHP upload_max_filesize/post_max_size can reject a file
            // here, and that gets a specific message via withValidator() in
            // Concerns\ValidatesVideoFile, not from a rule here — see that
            // trait's docblock for why it has to happen there.
            'video' => [
                'required', 'file',
                // Same mechanism as image uploads (see ImageUploader/
                // StoreProductRequest etc.): Laravel's `mimes` rule checks
                // the file's actual detected content type, not the
                // client-supplied filename/extension.
                'mimes:'.implode(',', config('salon.video_uploads.mimes')),
            ],
            'title' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
