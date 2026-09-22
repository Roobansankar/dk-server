<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->service_category_id,
            'category' => new ServiceCategoryResource($this->whenLoaded('category')),
            'gender' => $this->whenLoaded('category', fn () => $this->category->gender),
            'category_type' => $this->whenLoaded('category', fn () => $this->category->category_type),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'price' => $this->price !== null ? (float) $this->price : null,
            'advance_percentage' => $this->advance_percentage,
            'advance_amount' => $this->advance_amount,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
