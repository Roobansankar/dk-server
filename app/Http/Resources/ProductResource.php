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
	    'tax_percent' => $this->tax_percent !== null ? (float) $this->tax_percent : 0,
            'gst_inclusive' => (bool) $this->gst_inclusive,
            'stock_quantity' => (int) $this->stock_quantity,
            // Units sold, summed from 'sale' stock movements — only present
            // when the query loaded it (admin product list).
            'items_sold' => $this->whenHas('items_sold', fn ($sold) => abs((int) $sold)),
            'discount_amount' => $this->discount_amount,
            'status' => $this->status,
            'is_featured' => (bool) $this->is_featured,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
