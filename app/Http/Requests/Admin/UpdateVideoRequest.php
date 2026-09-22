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
            'video' => [
                'sometimes', 'file',
                'mimes:'.implode(',', config('salon.video_uploads.mimes')),
            ],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
