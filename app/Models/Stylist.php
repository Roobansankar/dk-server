<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Stylist extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'bio',
        'image_path',
        'status',
        'sort_order',
    ];

    protected $attributes = [
        'status' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** The services this professional offers (which also fixes their genders and categories). */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'stylist_service')
            ->withPivot(['price', 'advance_percentage'])
            ->withTimestamps();
    }

    /**
     * This professional's own price / advance for the services that have one,
     * as { "<service id>": { price, advance_percentage } }. A service with no
     * override (NULL) is left out and uses the service's standard terms. Returned
     * as an object so an empty map serialises as `{}`.
     */
    public function serviceTerms(): object
    {
        $terms = [];

        foreach ($this->services as $service) {
            $price = $service->pivot->price;
            $percentage = $service->pivot->advance_percentage;

            if ($price !== null || $percentage !== null) {
                $terms[$service->id] = [
                    'price' => $price !== null ? (float) $price : null,
                    'advance_percentage' => $percentage !== null ? (int) $percentage : null,
                ];
            }
        }

        return (object) $terms;
    }

    /**
     * The calendar dates they can be booked on, with the hours for each — see
     * StylistDateHour. A date with no rows is a date they are not available.
     */
    public function dateHours(): HasMany
    {
        return $this->hasMany(StylistDateHour::class);
    }

    /** Professionals who offer the given service. */
    public function scopeOffering($query, int $serviceId)
    {
        return $query->whereHas('services', fn ($q) => $q->where('services.id', $serviceId));
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
