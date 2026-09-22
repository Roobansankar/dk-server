<?php

namespace App\Http\Resources;

use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'reviewer_name' => $this->reviewer_name,
            'rating' => $this->rating,
            'review_text' => $this->review_text,
            'review_date' => $this->review_date?->toDateString(),
            'reviewer_avatar_url' => ImageUploader::url($this->reviewer_avatar_path),
            'is_published' => (bool) $this->is_published,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
