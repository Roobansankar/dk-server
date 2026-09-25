<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $inr = fn ($v) => '&#8377; ' . number_format((float) $v, 2);
        $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
        $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('h:i A') : '—';
        $paidInFull = $appointment->payment_status === 'paid';
    @endphp
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #201e1b; font-size: 11px; margin: 0; }
        .head { border-bottom: 2px solid #201e1b; padding-bottom: 10px; margin-bottom: 14px; }
        .brand { font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        .brand-sub { font-size: 10px; color: #6d6858; margin-top: 2px; }
        .doc-title { font-size: 15px; font-weight: bold; margin-top: 10px; letter-spacing: 2px; }
        .stamp {
            display: inline-block; margin-top: 6px; padding: 3px 12px;
            border: 2px solid #23503a; color: #23503a; border-radius: 4px;
            font-size: 11px; font-weight: bold; letter-spacing: 1.5px;
        }
        .stamp.pending { border-color: #9a6a00; color: #9a6a00; }
        .two { width: 100%; margin: 12px 0; }
        .two td { vertical-align: top; width: 50%; padding: 0; }
        .box { border: 1px solid #d0c7b3; padding: 10px 12px; }
        .box h4 { margin: 0 0 6px; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6d6858; }
        .box p { margin: 2px 0; font-size: 11px; }
        .box .big { font-size: 13px; font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th { text-align: left; background: #eee7d8; color: #4a4740; font-size: 9px;
            text-transform: uppercase; letter-spacing: 0.5px; padding: 7px 8px; border-bottom: 1px solid #d0c7b3; }
        table.items td { padding: 8px; border-bottom: 1px solid #e4ddce; font-size: 11px; }
        .num { text-align: right; }
        table.totals { width: 45%; margin-left: 55%; border-collapse: collapse; margin-top: 10px; }
        table.totals td { padding: 4px 8px; font-size: 11px; }
        table.totals .num { text-align: right; }
        table.totals tr.grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #201e1b; padding-top: 7px; }
        .thanks { margin-top: 22px; text-align: center; color: #6d6858; font-size: 10px; }
        .footer { position: fixed; bottom: -20px; left: 0; right: 0; text-align: center;
                  color: #928c7b; font-size: 8px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">{{ $salonName }}</div>
        @if ($salonPhone || $salonAddress)
            <div class="brand-sub">
                @if ($salonPhone){{ $salonPhone }}@endif
                @if ($salonPhone && $salonAddress) &nbsp;·&nbsp; @endif
                @if ($salonAddress){{ $salonAddress }}@endif
            </div>
        @endif
        <div class="doc-title">BILL / INVOICE</div>
        @if ($paidInFull)
            <div><span class="stamp">PAID IN FULL</span></div>
        @else
            <div><span class="stamp pending">{{ strtoupper(str_replace('_', ' ', $appointment->payment_status)) }}</span></div>
        @endif
    </div>

    <table class="two">
        <tr>
            <td style="padding-right: 8px;">
                <div class="box">
                    <h4>Billed to</h4>
                    <p class="big">{{ $appointment->customer_name }}</p>
                    <p>{{ $appointment->phone }}</p>
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="box">
                    <h4>Bill details</h4>
                    <p><strong>Bill no:</strong> {{ $appointment->reference }}</p>
                    <p><strong>Bill date:</strong> {{ $generatedAt->format('d M Y, h:i A') }}</p>
                    <p><strong>Appointment:</strong> {{ $fmtDate($appointment->appointment_date) }} at {{ $fmtTime($appointment->appointment_time) }}</p>
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Service</th>
                <th>Stylist</th>
                <th class="num">Price (&#8377;)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>
                    {{ $appointment->service_name ?? '—' }}
                    <br><span style="color:#928c7b">{{ $appointment->category_name ?? '' }}</span>
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
        <tr>
            <td>Paid</td>
            <td class="num">{!! $inr($appointment->amount_received) !!}</td>
        </tr>
        <tr class="grand">
            <td>Amount received</td>
            <td class="num">{!! $inr($appointment->amount_received) !!}</td>
        </tr>
    </table>

    <p class="thanks">Thank you for visiting {{ $salonName }}! We look forward to seeing you again.</p>

    <div class="footer">{{ $salonName }} — bill {{ $appointment->reference }} — generated {{ $generatedAt->format('d M Y H:i') }}</div>
</body>
</html>
