<?php

namespace App\Http\Resources;

use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gender' => $this->gender,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category_type' => $this->category_type,
            'image_url' => ImageUploader::url($this->image_path),
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'services_count' => $this->whenCounted('services'),
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
