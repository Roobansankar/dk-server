<?php

namespace App\Http\Resources;

use App\Models\StylistDateHour;
use App\Models\StylistWorkHour;
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
            'work_hours_count' => $this->whenCounted('workHours'),
            // The services they offer and their weekly hours (index 0 = Sunday …
            // 6 = Saturday, each a list of {start, end}) — what the booking
            // page uses to show only what this professional actually does.
            'service_ids' => $this->whenLoaded('services', fn () => $this->services->pluck('id')->values()->all()),
            'work_hours' => $this->whenLoaded('workHours', fn () => StylistWorkHour::weekly($this->workHours)),
            // Calendar dates that override the weekly pattern: { "2026-10-05": [] (day off),
            // "2026-10-06": [{start, end}] (custom hours) }. A date not listed follows the week.
            'date_hours' => $this->whenLoaded('dateHours', fn () => StylistDateHour::byDate($this->dateHours)),
            'custom_days_count' => $this->when(
                array_key_exists('custom_days_count', $this->getAttributes()),
                fn () => (int) $this->custom_days_count,
            ),
            // Bookable = offers at least one service AND can work at some point:
            // a weekly pattern, or custom hours on upcoming dates.
            'bookable' => $this->when(
                $this->relationLoaded('services') && $this->relationLoaded('workHours'),
                fn () => $this->services->isNotEmpty() && (
                    $this->workHours->isNotEmpty()
                    || ($this->relationLoaded('dateHours') && $this->dateHours->contains(fn ($row) => ! $row->isOff()))
                ),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
