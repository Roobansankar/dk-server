<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\SiteSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * The bill / invoice PDF for one appointment. One place builds it, so the file
 * staff download in the admin and the one WhatsApp sends the customer when the
 * appointment is marked "Paid in full" are exactly the same document (the
 * header stamp reads PAID IN FULL only when payment_status = paid).
 */
class BillPdf
{
    public static function make(Appointment $appointment): DomPdf
    {
        $settings = SiteSetting::allValues();

        return Pdf::loadView('pdf.bill', [
            'appointment' => $appointment,
            'salonName' => $settings->get('salon_name', 'DK StyleHub'),
            'salonPhone' => $settings->get('phone'),
            'salonAddress' => $settings->get('address'),
            // The studio's clock (the app itself runs in UTC), so "Bill date" is the time in India.
            'generatedAt' => now(BookingAvailability::TZ),
        ])->setPaper('a4');
    }

    /** "bill-APT-K3M9X2QA.pdf" */
    public static function filename(Appointment $appointment): string
    {
        return 'bill-'.$appointment->reference.'.pdf';
    }
}
