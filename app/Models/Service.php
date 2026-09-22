<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
