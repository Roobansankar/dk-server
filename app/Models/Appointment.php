<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Appointment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_REJECTED,
    ];

    public const PAYMENT_UNPAID = 'unpaid';

    public const PAYMENT_ADVANCE_PAID = 'advance_paid';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_STATUSES = [
        self::PAYMENT_UNPAID,
        self::PAYMENT_ADVANCE_PAID,
        self::PAYMENT_PAID,
    ];

    public const SOURCE_ONLINE = 'online';

    public const SOURCE_OFFLINE = 'offline';

    public const SOURCES = [self::SOURCE_ONLINE, self::SOURCE_OFFLINE];

    protected $fillable = [
        'reference',
        'user_id',
        'customer_name',
        'phone',
        'gender',
        'source',
        'service_category_id',
        'service_id',
        'stylist_id',
        'category_name',
        'service_name',
        'stylist_name',
        'duration_minutes',
        'service_price',
        'advance_percentage',
        'advance_amount',
        'appointment_date',
        'appointment_time',
        'message',
        'notes',
        'status',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'appointment_time' => 'datetime:H:i',
            'duration_minutes' => 'integer',
            'service_price' => 'decimal:2',
            'advance_percentage' => 'integer',
            'advance_amount' => 'decimal:2',
        ];
    }

    /** Amount already collected, based on the recorded payment status. */
    public function getAmountReceivedAttribute(): float
    {
        return match ($this->payment_status) {
            self::PAYMENT_PAID => (float) ($this->service_price ?? 0),
            self::PAYMENT_ADVANCE_PAID => (float) ($this->advance_amount ?? 0),
            default => 0.0,
        };
    }

    /** Balance still owed by the customer. */
    public function getRemainingAmountAttribute(): float
    {
        return round(max(0, ((float) ($this->service_price ?? 0)) - $this->amount_received), 2);
    }

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->reference ??= static::generateReference();
            $appointment->status ??= self::STATUS_PENDING;
            $appointment->payment_status ??= self::PAYMENT_UNPAID;
            $appointment->source ??= self::SOURCE_ONLINE;
        });
    }

    /**
     * Copy the current service configuration onto the appointment. When a
     * professional is given, their own price / advance for the service (if the
     * admin set one) is what gets snapshotted — and therefore what is charged.
     */
    public function applyServiceSnapshot(Service $service, ?Stylist $stylist = null): void
    {
        $terms = $service->termsFor($stylist);

        $this->service_id = $service->id;
        $this->service_category_id = $service->service_category_id;
        $this->category_name = $service->category?->name;
        $this->service_name = $service->name;
        $this->duration_minutes = $service->duration_minutes;
        $this->service_price = $terms['price'];
        $this->advance_percentage = $terms['advance_percentage'];
        $this->advance_amount = $terms['advance_amount'];
    }

    /** Snapshot the chosen stylist. Null = "any available stylist". */
    public function applyStylistSnapshot(?Stylist $stylist): void
    {
        $this->stylist_id = $stylist?->id;
        $this->stylist_name = $stylist?->name;
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Admin → Payments & Completed: every appointment with money on record —
     * a verified advance/full payment (PaymentController::verify() sets
     * payment_status and status atomically in one transaction, so there's no
     * window where a row is "paid" but not yet visible here) — plus every
     * appointment marked completed regardless of payment (so a completed
     * unpaid/offline booking still shows for reconciliation). A pending,
     * unverified appointment always has payment_status = unpaid, so this
     * never surfaces an unconfirmed/unpaid booking.
     */
    public function scopePaidOrCompleted($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_COMPLETED)
                ->orWhere('payment_status', '!=', self::PAYMENT_UNPAID);
        });
    }

    public static function generateReference(): string
    {
        do {
            $reference = 'APT-'.strtoupper(Str::random(8));
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function stylist(): BelongsTo
    {
        return $this->belongsTo(Stylist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
