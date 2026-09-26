<?php

namespace App\Http\Resources;

use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComboResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
	   'bundle_price' => $this->bundle_price !== null ? (float) $this->bundle_price : null,
	   'tax_percent' => $this->tax_percent !== null ? (float) $this->tax_percent : 0,
            // The cover — always the first of `images` — for cards and lists.
            'image_url' => ImageUploader::url($this->image_path),
            // Up to four photos, in the order the detail page shows them.
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => ImageUploader::url($image->path),
            ])->values()),
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'name' => $item->product?->name,
                'image_url' => ImageUploader::url($item->product?->image_path),
                // Combo-specific price — what this product costs inside the combo.
                'price' => (float) $item->price,
                // The product's normal storefront price, for reference only.
                'selling_price' => $item->product?->selling_price !== null ? (float) $item->product->selling_price : null,
                'stock_quantity' => (int) ($item->product?->stock_quantity ?? 0),
                'available' => (bool) ($item->product && ! $item->product->trashed() && $item->product->status),
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
