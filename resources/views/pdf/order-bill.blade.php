<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $inr = fn ($v) => '&#8377; ' . number_format((float) $v, 2);
        $billedAt = $order->paid_at ?? $order->created_at;
        $paid = $order->payment_status === 'paid';
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
        table.items td { padding: 8px; border-bottom: 1px solid #e4ddce; font-size: 11px; vertical-align: top; }
        .num { text-align: right; }
        .muted { color: #928c7b; }
        .sub { font-size: 10px; color: #6d6858; margin-top: 3px; }
        table.totals { width: 45%; margin-left: 55%; border-collapse: collapse; margin-top: 10px; }
        table.totals td { padding: 4px 8px; font-size: 11px; }
        table.totals .num { text-align: right; }
        table.totals tr.grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #201e1b; padding-top: 7px; }
        .note { margin-top: 14px; color: #6d6858; font-size: 10px; }
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
        @if ($paid)
            <div><span class="stamp">PAID</span></div>
        @else
            <div><span class="stamp pending">{{ strtoupper(str_replace('_', ' ', $order->payment_status)) }}</span></div>
        @endif
    </div>

    <table class="two">
        <tr>
            <td style="padding-right: 8px;">
                <div class="box">
                    <h4>Billed to</h4>
                    <p class="big">{{ $order->customer_name }}</p>
                    <p>{{ $order->phone }}</p>
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="box">
                    <h4>Order details</h4>
                    <p><strong>Order no:</strong> {{ $order->order_number }}</p>
                    <p><strong>Date:</strong> {{ $billedAt?->timezone('Asia/Kolkata')->format('d M Y, h:i A') }}</p>
                    @if ($order->razorpay_payment_id)
                        <p><strong>Payment ID:</strong> {{ $order->razorpay_payment_id }}</p>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>#</th>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price (&#8377;)</th>
                <th class="num">Amount (&#8377;)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        {{ $item->name }}
                        @if ($item->item_type === 'combo' && $item->selectedProducts->isNotEmpty())
                            <div class="sub">{{ $item->selectedProducts->pluck('product_name')->implode(', ') }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{!! $inr($item->unit_price) !!}</td>
                    <td class="num">{!! $inr($item->line_total) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Order total</td>
            <td class="num">{!! $inr($order->total) !!}</td>
        </tr>
        <tr class="grand">
            <td>Amount paid</td>
            <td class="num">{!! $inr($order->amount_paid) !!}</td>
        </tr>
    </table>

    <p class="note">Prices shown include applicable taxes.</p>

    <p class="thanks">Thank you for shopping with {{ $salonName }}!</p>

    <div class="footer">{{ $salonName }} — order {{ $order->order_number }}</div>
</body>
</html>
