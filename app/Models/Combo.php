<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A "combo product": a set of existing products, each with its own
 * combo-specific price (ComboItem::price).
 *
 * Customers may buy any non-empty subset. A configured bundle price is
 * used when all combo products are selected; otherwise the selected
 * items' combo prices are summed server-side.
 *
 * See App\Support\OrderPricing.
 */
class Combo extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'bundle_price',
        'tax_percent',
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
            'bundle_price' => 'decimal:2',
	    'tax_percent' => 'decimal:2',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComboItem::class)->orderBy('sort_order')->orderBy('id');
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
