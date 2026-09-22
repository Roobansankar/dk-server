<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared query filters for the appointment list, history and payment report
 * so all three stay consistent.
 */
class AppointmentFilters
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(Appointment::STATUSES)],
            'payment_status' => ['sometimes', Rule::in(Appointment::PAYMENT_STATUSES)],
            'source' => ['sometimes', Rule::in(Appointment::SOURCES)],
            'gender' => ['sometimes', 'in:male,female,unisex'],
            'category_id' => ['sometimes', 'integer'],
            'service_id' => ['sometimes', 'integer'],
            'stylist_id' => ['sometimes', 'integer'],
            'date' => ['sometimes', 'date'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public static function apply(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('gender'), fn ($q) => $q->where('gender', $request->string('gender')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('service_category_id', $request->integer('category_id')))
            ->when($request->filled('service_id'), fn ($q) => $q->where('service_id', $request->integer('service_id')))
            ->when($request->filled('stylist_id'), fn ($q) => $q->where('stylist_id', $request->integer('stylist_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('appointment_date', $request->date('date')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('appointment_date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('appointment_date', '<=', $request->date('date_to')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($sub) => $sub
                    ->where('customer_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('service_name', 'like', $term)
                    ->orWhere('category_name', 'like', $term));
            });
    }

    /** Human-readable summary of the active filters, for report headers. */
    public static function describe(Request $request): array
    {
        $labels = [];
        if ($request->filled('status')) {
            $labels['Status'] = ucfirst($request->string('status'));
        }
        if ($request->filled('payment_status')) {
            $labels['Payment'] = ucwords(str_replace('_', ' ', $request->string('payment_status')));
        }
        if ($request->filled('source')) {
            $labels['Source'] = ucfirst($request->string('source'));
        }
        if ($request->filled('gender')) {
            $labels['Gender'] = ucfirst($request->string('gender'));
        }
        if ($request->filled('search')) {
            $labels['Search'] = (string) $request->string('search');
        }
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $labels['Date range'] = trim(($request->input('date_from') ?: '…').' to '.($request->input('date_to') ?: '…'));
        }

        return $labels;
    }
}
