<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $inr = fn ($v) => '&#8377; ' . number_format((float) $v, 2);
        $billedAt = $order->paid_at ?? $order->created_at;
        $paid = $order->payment_status === 'paid';
        $instagramHandle = ! empty($instagramUrl) ? trim(parse_url($instagramUrl, PHP_URL_PATH) ?? '', '/') : null;
    @endphp
    @include('pdf.partials.styles')
</head>
<body>
    @include('pdf.partials.header', [
        'docTitle' => 'Invoice',
        'docRef' => 'Order no. ' . $order->order_number,
        'badgeText' => $paid ? 'PAID' : strtoupper(str_replace('_', ' ', $order->payment_status)),
        'badgePaid' => $paid,
    ])

    <div class="content">
        <div class="cards-wrap">
            <table class="cards">
                <tr>
                    <td class="card" style="width: 50%;">
                        <h4>Billed to</h4>
                        <p class="name">{{ $order->customer_name }}</p>
                        <p>{{ $order->phone }}</p>
                    </td>
                    <td class="card" style="width: 50%;">
                        <h4>Order details</h4>
                        <p><span class="k">Order no.</span> &nbsp;{{ $order->order_number }}</p>
                        <p><span class="k">Date</span> &nbsp;{{ $billedAt?->timezone('Asia/Kolkata')->format('d M Y, h:i A') }}</p>
                    </td>
                </tr>
            </table>
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 24px;">#</th>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Price (&#8377;)</th>
                    <th class="num">Amount (&#8377;)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr class="{{ $loop->even ? 'alt' : '' }}">
                        <td>{{ $loop->iteration }}</td>
                        <td>
                            <div class="item-name">{{ $item->name }}</div>
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
                            <td>Subtotal</td>
                            <td class="num">{!! $inr($order->subtotal) !!}</td>
                        </tr>
                        <tr class="grand">
                            <td>Grand total</td>
                            <td class="num">{!! $inr($order->total) !!}</td>
                        </tr>
                        <tr>
                            <td>Amount paid</td>
                            <td class="num">{!! $inr($order->amount_paid) !!}</td>
                        </tr>
                    </table>

                    @if ($paid)
                        <div class="paid-via">
                            <span class="tick">&#10003;</span> <b>Payment method</b> &mdash; Paid online via Razorpay
                        </div>
                    @endif

                    <p class="note">Prices shown include applicable taxes.</p>
                </td>
            </tr>
        </table>

        <div class="thanks">
            <div class="rule"></div>
            <div class="line1">Thank you for shopping with {{ $salonName ?? 'DK StyleHub' }}!</div>
            <div class="line2">Style &bull; Confidence &bull; You</div>
        </div>
    </div>

    @include('pdf.partials.footer', [
        'docRef' => 'order ' . $order->order_number,
        'salonName' => $salonName,
        'salonPhone' => $salonPhone,
        'salonAddress' => $salonAddress,
        'instagramHandle' => $instagramHandle,
        'terms' => 'Products once delivered are only replaced for a manufacturing defect.',
    ])
</body>
</html>
