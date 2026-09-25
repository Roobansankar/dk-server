<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'service_category_id',
        'name',
        'slug',
        'description',
        'duration_minutes',
        'price',
        'advance_percentage',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'advance_percentage' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** Advance payable to confirm a booking, derived from price + percentage. */
    public function getAdvanceAmountAttribute(): float
    {
        return round(((float) ($this->price ?? 0)) * $this->advance_percentage / 100, 2);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /** The professionals who offer this service. */
    public function stylists(): BelongsToMany
    {
        return $this->belongsToMany(Stylist::class, 'stylist_service')
            ->withPivot(['price', 'advance_percentage'])
            ->withTimestamps();
    }

    /**
     * The price and advance for this service when $stylist does it: their own
     * terms where the admin set them, otherwise the service's standard ones.
     * With no stylist this is just the service's own price and advance.
     *
     * @return array{price: float|null, advance_percentage: int, advance_amount: float}
     */
    public function termsFor(?Stylist $stylist): array
    {
        $pivot = $stylist
            ? $stylist->services()->where('services.id', $this->id)->first()?->pivot
            : null;

        $price = $pivot?->price !== null
            ? (float) $pivot->price
            : ($this->price !== null ? (float) $this->price : null);

        $percentage = $pivot?->advance_percentage !== null
            ? (int) $pivot->advance_percentage
            : (int) $this->advance_percentage;

        return [
            'price' => $price,
            'advance_percentage' => $percentage,
            'advance_amount' => round(($price ?? 0) * $percentage / 100, 2),
        ];
    }

    /** Gender is inherited from the parent category. */
    public function getGenderAttribute(): ?string
    {
        return $this->category?->gender;
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
