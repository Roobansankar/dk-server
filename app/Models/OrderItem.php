<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_COMBO = 'combo';

    public const TYPES = [self::TYPE_PRODUCT, self::TYPE_COMBO];

    protected $fillable = [
        'item_type',
        'product_id',
        'combo_id',
        'name',
        'unit_price',
        'quantity',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Selected combo products (combo lines only). */
    public function selectedProducts(): HasMany
    {
        return $this->hasMany(OrderItemProduct::class)->orderBy('id');
    }
}
