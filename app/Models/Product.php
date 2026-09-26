<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    /** How many products may be featured at once. */
    public const MAX_FEATURED = 3;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'image_path',
        'mrp',
        'selling_price',
        'tax_percent',
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
	    'tax_percent' => 'decimal:2',
            'gst_inclusive' => 'boolean',
            'status' => 'boolean',
            'is_featured' => 'boolean',
            'featured_at' => 'datetime',
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

    public function stockMovements(): HasMany
    {
        return $this->hasMany(ProductStockMovement::class);
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
     * Set this product's featured flag, enforcing the "at most MAX_FEATURED
     * featured products" rule at the database level.
     *
     * The whole thing runs in one transaction with `SELECT ... FOR UPDATE` on
     * every row that is currently featured (plus this one), so two admins
     * toggling featured at the same moment are serialised and can never leave
     * more than MAX_FEATURED products featured. Featuring one more than the
     * limit un-features the one featured longest ago (oldest `featured_at`).
     * Turning a product OFF simply clears it.
     */
    public function applyFeatured(bool $featured): void
    {
        DB::transaction(function () use ($featured) {
            $locked = static::query()
                ->where(fn ($q) => $q->where('is_featured', true)->orWhereKey($this->getKey()))
                ->lockForUpdate()
                ->get();

            $alreadyFeatured = (bool) $locked->firstWhere('id', $this->getKey())?->is_featured;

            if ($featured && ! $alreadyFeatured) {
                $others = static::query()
                    ->where('is_featured', true)
                    ->whereKeyNot($this->getKey())
                    ->orderBy('featured_at')
                    ->orderBy('id')
                    ->pluck('id');

                $excess = $others->count() - (self::MAX_FEATURED - 1);

                if ($excess > 0) {
                    static::query()
                        ->whereKey($others->take($excess)->all())
                        ->update(['is_featured' => false, 'featured_at' => null]);
                }
            }

            $this->forceFill([
                'is_featured' => $featured,
                'featured_at' => $featured ? ($alreadyFeatured ? $this->featured_at : now()) : null,
            ])->save();
        });

        $this->refresh();
    }
}
