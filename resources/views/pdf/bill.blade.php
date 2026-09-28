<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $inr = fn ($v) => '&#8377; ' . number_format((float) $v, 2);
        $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
        $paidInFull = $appointment->payment_status === 'paid';
        $balance = (float) $appointment->remaining_amount;

        // "08:00 PM – 09:00 PM" when the service length is known, else just the start.
        $start = $appointment->appointment_time ? \Illuminate\Support\Carbon::parse($appointment->appointment_time) : null;
        $minutes = (int) $appointment->duration_minutes;
        $slot = $start === null
            ? '—'
            : $start->format('h:i A').($minutes > 0 ? ' – '.$start->copy()->addMinutes($minutes)->format('h:i A') : '');

        // How this was paid — only stated when it's unambiguous. The advance on an
        // online booking is always Razorpay; only the method staff later collected
        // the balance in, if any, is worth a single clear line.
        $methodLabel = fn ($m) => ['upi' => 'UPI', 'cash' => 'Cash', 'card' => 'Card'][$m] ?? null;

        // A genuine two-part payment — an advance taken online, then a balance
        // collected separately — worth itemising instead of one lump "Amount
        // received". Paying the whole price as the "advance" (no separate
        // balance) isn't a split; that's just a single online payment.
        $advance = (float) ($appointment->advance_amount ?? 0);
        $servicePrice = (float) ($appointment->service_price ?? 0);
        $balancePortion = round(max(0, $servicePrice - $advance), 2);
        $showSplit = $appointment->source !== 'offline' && $advance > 0 && $balancePortion > 0;
        $balanceMethodLabel = $methodLabel($appointment->balance_payment_method);

        $paidVia = null;
        if ($paidInFull && $appointment->source === 'offline' && $methodLabel($appointment->payment_method)) {
            $paidVia = $methodLabel($appointment->payment_method);
        } elseif ($paidInFull && $appointment->source !== 'offline' && ! $showSplit) {
            $paidVia = $methodLabel($appointment->balance_payment_method) ?? 'Online';
        }

        $instagramHandle = ! empty($instagramUrl) ? trim(parse_url($instagramUrl, PHP_URL_PATH) ?? '', '/') : null;
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
                        <h4>Customer details</h4>
                        <p class="name">{{ $appointment->customer_name ?? '—' }}</p>
                        <p><span class="k">Mobile</span> &nbsp;{{ $appointment->phone }}</p>
                    </td>
                    <td class="card" style="width: 33%;">
                        <h4>Appointment</h4>
                        <p><span class="k">Date</span> &nbsp;{{ $fmtDate($appointment->appointment_date) }}</p>
                        <p><span class="k">Time</span> &nbsp;{{ $slot }}</p>
                        <p><span class="k">Stylist</span> &nbsp;{{ $appointment->stylist_name ?? 'Any available' }}</p>
                    </td>
                    <td class="card" style="width: 33%;">
                        <h4>Bill &amp; location</h4>
                        <p><span class="k">No.</span> &nbsp;{{ $appointment->reference }}</p>
                        <p><span class="k">Date</span> &nbsp;{{ $generatedAt->format('d M Y, h:i A') }}</p>
                        @if (! empty($salonAddress))
                            <p><span class="k">At</span> &nbsp;{{ $salonName ?? 'DK StyleHub' }}</p>
                        @endif
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

        <table class="bottom-grid">
            <tr>
                <td class="extras-col">
                    @include('pdf.partials.extras', [
                        'googleReviewUrl' => $googleReviewUrl ?? null,
                    ])
                </td>
                <td class="summary-col">
                    <table class="totals">
                        <tr>
                            <td>Service total</td>
                            <td class="num">{!! $inr($appointment->service_price ?? 0) !!}</td>
                        </tr>
                        <tr class="grand">
                            <td>Grand total</td>
                            <td class="num">{!! $inr($appointment->service_price ?? 0) !!}</td>
                        </tr>
                        @if ($showSplit)
                            <tr>
                                <td>Advance paid (Online)</td>
                                <td class="num">{!! $inr($advance) !!}</td>
                            </tr>
                            @if ($paidInFull)
                                <tr>
                                    <td>Balance paid{{ $balanceMethodLabel ? " ({$balanceMethodLabel})" : '' }}</td>
                                    <td class="num">{!! $inr($balancePortion) !!}</td>
                                </tr>
                                <tr>
                                    <td>Amount received</td>
                                    <td class="num">{!! $inr($appointment->amount_received) !!}</td>
                                </tr>
                            @else
                                <tr>
                                    <td><b>Balance due</b></td>
                                    <td class="num"><b>{!! $inr($balance) !!}</b></td>
                                </tr>
                            @endif
                        @else
                            <tr>
                                <td>Amount received</td>
                                <td class="num">{!! $inr($appointment->amount_received) !!}</td>
                            </tr>
                            @if ($balance > 0)
                                <tr>
                                    <td><b>Balance due</b></td>
                                    <td class="num"><b>{!! $inr($balance) !!}</b></td>
                                </tr>
                            @endif
                        @endif
                    </table>

                    @if ($paidVia)
                        <div class="paid-via">
                            <span class="tick">&#10003;</span> <b>Payment method</b> &mdash; Paid via {{ $paidVia }}
                        </div>
                    @endif
                </td>
            </tr>
        </table>

        <div class="thanks">
            <div class="rule"></div>
            <div class="line1">Thank you for visiting {{ $salonName ?? 'DK StyleHub' }}!</div>
            <div class="line2">Style &bull; Confidence &bull; You</div>
        </div>
    </div>

    @include('pdf.partials.footer', [
        'docRef' => 'bill ' . $appointment->reference,
        'salonName' => $salonName,
        'salonPhone' => $salonPhone,
        'salonAddress' => $salonAddress,
        'instagramHandle' => $instagramHandle,
        'terms' => 'Services once completed are non-refundable.',
        'generatedAt' => $generatedAt,
    ])
</body>
</html>
