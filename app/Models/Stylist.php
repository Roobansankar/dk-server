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
        return $this->belongsToMany(Service::class, 'stylist_service')->withTimestamps();
    }

    /** Their weekly working hours — see StylistWorkHour. */
    public function workHours(): HasMany
    {
        return $this->hasMany(StylistWorkHour::class);
    }

    /** Date-specific hours (days off / custom days) that override the weekly pattern. */
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
