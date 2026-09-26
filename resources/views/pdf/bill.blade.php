<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $inr = fn ($v) => '&#8377; ' . number_format((float) $v, 2);
        $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
        $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('h:i A') : '—';
        $paidInFull = $appointment->payment_status === 'paid';
        $balance = (float) $appointment->remaining_amount;

        // "08:00 PM – 09:00 PM" when the service length is known, else just the start.
        $start = $appointment->appointment_time ? \Illuminate\Support\Carbon::parse($appointment->appointment_time) : null;
        $minutes = (int) $appointment->duration_minutes;
        $slot = $start === null
            ? '—'
            : $start->format('h:i A').($minutes > 0 ? ' – '.$start->copy()->addMinutes($minutes)->format('h:i A') : '');
    @endphp
    @include('pdf.partials.styles')
</head>
<body>
    @include('pdf.partials.header', [
        'docTitle' => 'Invoice',
        'docRef' => 'Bill no. ' . $appointment->reference,
        'badgeText' => $paidInFull ? 'PAID IN FULL' : strtoupper(str_replace('_', ' ', $appointment->payment_status)),
        'badgePaid' => $paidInFull,
    ])

    <div class="content">
        <div class="cards-wrap">
            <table class="cards">
                <tr>
                    <td class="card" style="width: 34%;">
                        <h4>Billed to</h4>
                        <p class="name">{{ $appointment->customer_name }}</p>
                        <p>{{ $appointment->phone }}</p>
                    </td>
                    <td class="card" style="width: 33%;">
                        <h4>Appointment</h4>
                        <p><span class="k">Date</span> &nbsp;{{ $fmtDate($appointment->appointment_date) }}</p>
                        <p><span class="k">Time</span> &nbsp;{{ $slot }}</p>
                    </td>
                    <td class="card" style="width: 33%;">
                        <h4>Bill details</h4>
                        <p><span class="k">No.</span> &nbsp;{{ $appointment->reference }}</p>
                        <p><span class="k">Date</span> &nbsp;{{ $generatedAt->format('d M Y, h:i A') }}</p>
                    </td>
                </tr>
            </table>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 24px;">#</th>
                    <th>Service</th>
                    <th>Stylist</th>
                    <th class="num">Price (&#8377;)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>
                        <div class="item-name">{{ $appointment->service_name ?? '—' }}</div>
                        @if (! empty($appointment->category_name))
                            <div class="sub">{{ $appointment->category_name }}</div>
                        @endif
                    </td>
                    <td>{{ $appointment->stylist_name ?? 'Any available' }}</td>
                    <td class="num">{!! $inr($appointment->service_price ?? 0) !!}</td>
                </tr>
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td>Service total</td>
                <td class="num">{!! $inr($appointment->service_price ?? 0) !!}</td>
            </tr>
            @if ($balance > 0)
                <tr>
                    <td>Balance due</td>
                    <td class="num">{!! $inr($balance) !!}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>Amount received</td>
                <td class="num">{!! $inr($appointment->amount_received) !!}</td>
            </tr>
        </table>

        <div class="thanks">
            <div class="rule"></div>
            Thank you for visiting {{ $salonName }}!<br>We look forward to seeing you again.
        </div>
    </div>

    <div class="footer">
        <div class="line"></div>
        <p>{{ $salonName }} — bill {{ $appointment->reference }} — generated {{ $generatedAt->format('d M Y H:i') }}</p>
    </div>
</body>
</html>
