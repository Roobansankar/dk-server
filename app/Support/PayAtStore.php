<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\SiteSetting;
use Illuminate\Support\Collection;

/**
 * "Pay at Store" on the online booking flow: a booking whose full service
 * price is at or under the admin's saved limit (Settings → Payments,
 * `pay_at_store_max_amount`) may skip the online confirmation fee and pay
 * the whole bill at the studio.
 *
 * The limit is read fresh from settings on every check, so the booking API
 * always decides against the CURRENT value — never one the client saw or
 * sent. No saved (or a non-numeric / negative) limit means the option is off.
 */
class PayAtStore
{
    public const SETTING_KEY = 'pay_at_store_max_amount';

    /** The saved limit in INR, or null when not (validly) configured. */
    public static function limit(): ?float
    {
        $raw = SiteSetting::allValues()->get(self::SETTING_KEY);

        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $limit = (float) $raw;

        return $limit >= 0 && is_finite($limit) ? $limit : null;
    }

    /**
     * Whether a booking with this full price may be paid at the studio.
     * Compared in paise so a float rounding can't push an at-the-limit price
     * either side of the line. An unknown price is never eligible.
     */
    public static function allows(float|int|string|null $price): bool
    {
        $limit = self::limit();

        if ($limit === null || $price === null || $price === '' || ! is_numeric($price)) {
            return false;
        }

        return (int) round((float) $price * 100) <= (int) round($limit * 100);
    }

    /**
     * An earlier Pay at Store booking from this same request — same customer
     * phone, professional, date, start time and services. Only a retry
     * (double click, dropped response) can match: any other booking for that
     * slot would have been refused. Call under the professional's day lock
     * when the answer decides whether to create a booking.
     *
     * @param  Collection<int, Service>  $services  in booking order
     */
    public static function findRetry(string $phone, int $stylistId, string $dateIso, string $time, Collection $services): ?Appointment
    {
        $first = $services->first();
        if (! $first) {
            return null;
        }

        $serviceName = $services->map(fn ($s) => $s->name)->implode(', ');

        return Appointment::query()
            ->where('source', Appointment::SOURCE_ONLINE)
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where('payment_method', Appointment::PAYMENT_METHOD_PAY_AT_STORE)
            ->where('stylist_id', $stylistId)
            ->whereDate('appointment_date', $dateIso)
            ->where('phone', $phone)
            ->where('service_id', $first->id)
            ->get()
            ->first(fn (Appointment $a) => $a->appointment_time?->format('H:i') === $time
                && $a->service_name === $serviceName);
    }
}
