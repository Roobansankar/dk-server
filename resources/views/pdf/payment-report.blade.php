<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $inr = fn ($v) => '&#8377; ' . number_format((float) $v, 2);
        $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '—';
    @endphp
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #201e1b; font-size: 11px; margin: 0; }
        .head { border-bottom: 2px solid #201e1b; padding-bottom: 10px; margin-bottom: 14px; }
        .brand { font-size: 20px; font-weight: bold; letter-spacing: 1px; }
        .doc-title { font-size: 13px; margin-top: 2px; color: #4a4740; }
        .meta { margin-top: 8px; color: #6d6858; font-size: 10px; }
        .meta strong { color: #201e1b; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { text-align: left; background: #eee7d8; color: #4a4740; font-size: 9px;
             text-transform: uppercase; letter-spacing: 0.5px; padding: 6px 6px; border-bottom: 1px solid #d0c7b3; }
        td { padding: 6px 6px; border-bottom: 1px solid #e4ddce; font-size: 10px; }
        .num { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #201e1b; border-bottom: none; padding-top: 8px; }
        .cards { width: 100%; margin: 12px 0 4px; }
        .cards td { border: 1px solid #d0c7b3; padding: 8px 10px; width: 25%; }
        .cards .k { font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; color: #6d6858; }
        .cards .v { font-size: 13px; font-weight: bold; margin-top: 3px; }
        .pill { font-size: 9px; }
        .footer { position: fixed; bottom: -20px; left: 0; right: 0; text-align: center;
                  color: #928c7b; font-size: 8px; }
    </style>
</head>
<body>
    <div class="head">
        <div class="brand">{{ $salonName }}</div>
        <div class="doc-title">Completed Appointments &amp; Payment Report</div>
        <div class="meta">
            <strong>Period:</strong>
            {{ $dateFrom ? $fmtDate($dateFrom) : 'earliest' }} &ndash; {{ $dateTo ? $fmtDate($dateTo) : 'latest' }}
            &nbsp;|&nbsp; <strong>Generated:</strong> {{ $generatedAt->format('d M Y, H:i') }}
            @if (count($filters))
                <br><strong>Filters:</strong>
                @foreach ($filters as $k => $v) {{ $k }}: {{ $v }}@if (!$loop->last); @endif @endforeach
            @endif
        </div>
    </div>

    <table class="cards">
        <tr>
            <td><div class="k">Completed appointments</div><div class="v">{{ $summary['completed_appointments'] }}</div></td>
            <td><div class="k">Total service value</div><div class="v">{!! $inr($summary['total_service_value']) !!}</div></td>
            <td><div class="k">Advance received</div><div class="v">{!! $inr($summary['total_advance_received']) !!}</div></td>
            <td><div class="k">Remaining balance</div><div class="v">{!! $inr($summary['total_remaining']) !!}</div></td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Ref</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Service</th>
                <th class="num">Price</th>
                <th class="num">Adv %</th>
                <th class="num">Advance</th>
                <th class="num">Remaining</th>
                <th>Payment</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td>{{ $r->reference }}</td>
                    <td>{{ $fmtDate($r->appointment_date) }}</td>
                    <td>{{ $r->customer_name }}<br><span style="color:#928c7b">{{ $r->phone }}</span></td>
                    <td>{{ $r->service_name ?? '—' }}<br><span style="color:#928c7b">{{ $r->category_name }} · {{ ucfirst($r->gender) }}</span></td>
                    <td class="num">{!! $inr($r->service_price ?? 0) !!}</td>
                    <td class="num">{{ $r->advance_percentage }}%</td>
                    <td class="num">{!! $inr($r->advance_amount ?? 0) !!}</td>
                    <td class="num">{!! $inr($r->remaining_amount) !!}</td>
                    <td class="pill">{{ ucwords(str_replace('_', ' ', $r->payment_status)) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:#928c7b;padding:20px">No completed appointments for the selected filters.</td></tr>
            @endforelse
        </tbody>
        @if ($rows->count())
            <tfoot>
                <tr>
                    <td colspan="4">Totals ({{ $summary['completed_appointments'] }})</td>
                    <td class="num">{!! $inr($summary['total_service_value']) !!}</td>
                    <td></td>
                    <td class="num">{!! $inr($summary['total_advance_received']) !!}</td>
                    <td class="num">{!! $inr($summary['total_remaining']) !!}</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="footer">{{ $salonName }} — generated {{ $generatedAt->format('d M Y H:i') }} — for internal accounting use</div>
</body>
</html>
