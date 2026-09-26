<?php

namespace App\Support;

use App\Models\Order;
use App\Models\SiteSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * The bill / invoice PDF for one shop order. Built from the order's own price
 * snapshot (every line and total was fixed at checkout), so it always shows
 * what the customer actually paid — even after product prices change. Sent to
 * the customer on WhatsApp as soon as their payment is verified.
 */
class OrderBillPdf
{
    public static function make(Order $order): DomPdf
    {
        $settings = SiteSetting::allValues();

        return Pdf::loadView('pdf.order-bill', [
            'order' => $order->loadMissing('items.selectedProducts'),
            'salonName' => $settings->get('salon_name', 'DK StyleHub'),
            'salonPhone' => $settings->get('phone'),
            'salonAddress' => $settings->get('address'),
        ])->setPaper('a4');
    }

    /** "bill-ORD-K3M9X2QA.pdf" */
    public static function filename(Order $order): string
    {
        return 'bill-'.$order->order_number.'.pdf';
    }
}
