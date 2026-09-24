<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesVideoFile;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVideoRequest extends FormRequest
{
    use ValidatesVideoFile;

    public function authorize(): bool
    {
        return $this->user()->can('videos.manage');
    }

    public function rules(): array
    {
        return [
            // Same 25 MB ceiling as store (see StoreVideoRequest) — only
            // applies when a replacement file is actually uploaded.
            'video' => [
                'sometimes', 'file',
                'mimes:'.implode(',', config('salon.video_uploads.mimes')),
                'max:'.config('salon.video_uploads.max_kb'),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
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
