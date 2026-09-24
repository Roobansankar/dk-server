<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A priced product basket awaiting Razorpay payment. Holds the server-side
 * price snapshot and the Razorpay order it was opened with; a product Order
 * is only created from it once the payment signature is verified
 * (Public\ProductCheckoutController::verify). Unpaid checkouts are never
 * shown to admins.
 */
class ProductCheckout extends Model
{
    protected $fillable = [
        'reference',
        'user_id',
        'customer_name',
        'phone',
        'lines',
        'amount',
        'razorpay_order_id',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'amount' => 'decimal:2',
        ];
    }

    public static function generateReference(): string
    {
        do {
            $reference = 'CHK-'.strtoupper(Str::random(10));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
