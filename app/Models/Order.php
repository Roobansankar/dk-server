<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer's product order. Only ever created from a ProductCheckout after
 * its Razorpay payment signature is verified, so every order is paid.
 * Totals and every line are snapshots computed server-side at checkout
 * (App\Support\OrderPricing) and never recalculated, so later product/combo
 * price edits leave old orders intact. `status` (fulfilment) and
 * `payment_status` are independent.
 */
class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_DISPATCHED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The admin fulfilment actions, in order: a paid order starts pending,
     * then Confirmed (customer called + order verified) → Dispatched (handed
     * to the courier) → Delivered. No step can be skipped.
     */
    public const ADMIN_ACTIONS = [
        self::STATUS_CONFIRMED,
        self::STATUS_DISPATCHED,
        self::STATUS_DELIVERED,
    ];

    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED],
        self::STATUS_CONFIRMED => [self::STATUS_DISPATCHED],
        self::STATUS_DISPATCHED => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_REFUNDED = 'refunded';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_PAID,
        self::PAYMENT_FAILED,
        self::PAYMENT_REFUNDED,
    ];

    protected $fillable = [
        'user_id',
        'customer_name',
        'phone',
        'subtotal',
        'total',
        'amount_paid',
        'payment_status',
        'razorpay_order_id',
        'razorpay_payment_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->order_number ??= static::generateOrderNumber();
            $order->status ??= self::STATUS_PENDING;
            $order->payment_status ??= self::PAYMENT_UNPAID;
        });
    }

    public static function generateOrderNumber(): string
    {
        do {
            $number = 'ORD-'.strtoupper(Str::random(8));
        } while (static::where('order_number', $number)->exists());

        return $number;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
