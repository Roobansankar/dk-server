<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image_path',
        'mrp',
        'selling_price',
        'gst_inclusive',
        'status',
        'is_featured',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'mrp' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'gst_inclusive' => 'boolean',
            'status' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Saving vs MRP, or null when either price is missing. */
    public function getDiscountAmountAttribute(): ?float
    {
        if ($this->mrp === null || $this->selling_price === null) {
            return null;
        }

        return round(max(0, (float) $this->mrp - (float) $this->selling_price), 2);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Set this product's featured flag, enforcing the "exactly one featured
     * product at a time" rule at the database level.
     *
     * The whole thing runs in one transaction with `SELECT ... FOR UPDATE` on
     * every row that is currently featured (plus this one), so two admins
     * toggling featured at the same moment are serialised and can never leave
     * more than one product featured. Turning a product OFF simply clears it and
     * leaves nothing featured, which is the intended behaviour.
     */
    public function applyFeatured(bool $featured): void
    {
        DB::transaction(function () use ($featured) {
            static::query()
                ->where(fn ($q) => $q->where('is_featured', true)->orWhereKey($this->getKey()))
                ->lockForUpdate()
                ->get();

            if ($featured) {
                static::query()
                    ->where('is_featured', true)
                    ->whereKeyNot($this->getKey())
                    ->update(['is_featured' => false]);
            }

            $this->forceFill(['is_featured' => $featured])->save();
        });

        $this->refresh();
    }
}
