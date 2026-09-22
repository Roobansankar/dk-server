<?php

namespace App\Http\Resources;

use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image_url' => ImageUploader::url($this->image_path),
            'mrp' => $this->mrp !== null ? (float) $this->mrp : null,
            'selling_price' => $this->selling_price !== null ? (float) $this->selling_price : null,
            'gst_inclusive' => (bool) $this->gst_inclusive,
            'discount_amount' => $this->discount_amount,
            'status' => $this->status,
            'is_featured' => (bool) $this->is_featured,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
