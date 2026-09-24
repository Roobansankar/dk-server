<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboItem extends Model
{
    protected $fillable = [
        'combo_id',
        'product_id',
        'price',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    /** Includes soft-deleted products so an admin can still see what was configured. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
