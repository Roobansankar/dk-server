<?php

namespace App\Http\Resources;

use App\Models\StylistDateHour;
use App\Models\StylistWeeklyHour;
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
            // Specific calendar dates that override the weekly schedule below:
            // { "2026-10-06": [{start, end}, …] }. A date not listed here follows
            // the weekly schedule instead (see date_closures for a date explicitly
            // excluded from it).
            'date_hours' => $this->whenLoaded('dateHours', fn () => StylistDateHour::byDate($this->dateHours)),
            // Calendar dates explicitly marked not available despite the weekly
            // schedule. Only matters for a date with no entry in date_hours.
            'date_closures' => $this->whenLoaded(
                'dateClosures',
                fn () => $this->dateClosures->map(fn ($row) => $row->date->toDateString())->values(),
            ),
            // Their standing weekly schedule ("every Monday 10–6", no end date):
            // { "1": [{start, end}, …] }, keyed "0" (Sunday) … "6" (Saturday). A
            // specific date above, or a closure, overrides this for that one date.
            'weekly_hours' => $this->whenLoaded('weeklyHours', fn () => StylistWeeklyHour::byWeekday($this->weeklyHours)),
            'upcoming_days_count' => $this->when(
                array_key_exists('upcoming_days_count', $this->getAttributes()),
                fn () => (int) $this->upcoming_days_count,
            ),
            // Bookable = offers at least one service AND has hours on an upcoming
            // date, from a specific override or the weekly schedule.
            'bookable' => $this->when(
                $this->relationLoaded('services') && $this->relationLoaded('dateHours') && $this->relationLoaded('weeklyHours'),
                fn () => $this->services->isNotEmpty() && ($this->dateHours->isNotEmpty() || $this->weeklyHours->isNotEmpty()),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
