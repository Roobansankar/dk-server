<?php

namespace App\Http\Resources;

use App\Models\StylistDateHour;
use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StylistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'bio' => $this->bio,
            'image_url' => ImageUploader::url($this->image_path),
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'appointments_count' => $this->whenCounted('appointments'),
            'services_count' => $this->whenCounted('services'),
            // The services they offer — what the booking page uses to show only
            // what this professional actually does.
            'service_ids' => $this->whenLoaded('services', fn () => $this->services->pluck('id')->values()->all()),
            // Their own price / advance % where set: { "<service id>": { price, advance_percentage } }.
            'service_terms' => $this->whenLoaded('services', fn () => $this->serviceTerms()),
            // The dates they can be booked on and the hours for each:
            // { "2026-10-06": [{start, end}, …] }. A date not listed = not available.
            'date_hours' => $this->whenLoaded('dateHours', fn () => StylistDateHour::byDate($this->dateHours)),
            'upcoming_days_count' => $this->when(
                array_key_exists('upcoming_days_count', $this->getAttributes()),
                fn () => (int) $this->upcoming_days_count,
            ),
            // Bookable = offers at least one service AND has hours on an upcoming date.
            'bookable' => $this->when(
                $this->relationLoaded('services') && $this->relationLoaded('dateHours'),
                fn () => $this->services->isNotEmpty() && $this->dateHours->isNotEmpty(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
