<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->item_type,
            'product_id' => $this->product_id,
            'combo_id' => $this->combo_id,
            'name' => $this->name,
            'unit_price' => (float) $this->unit_price,
            'quantity' => $this->quantity,
            'line_total' => (float) $this->line_total,
            'selected_products' => $this->whenLoaded('selectedProducts', fn () => $this->selectedProducts->map(fn ($p) => [
                'product_id' => $p->product_id,
                'name' => $p->product_name,
                'price' => (float) $p->price,
            ])->values()),
        ];
    }
}
