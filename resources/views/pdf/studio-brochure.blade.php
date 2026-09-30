<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $inr = fn ($v) => $v === null ? '—' : '&#8377; ' . number_format((float) $v, 2);
        $dur = fn ($m) => $m ? (int) $m . ' min' : '—';
        $instagramHandle = ! empty($instagramUrl) ? trim(parse_url($instagramUrl, PHP_URL_PATH) ?? '', '/') : null;
    @endphp
    @include('pdf.partials.styles')
    <style>
        /* NOTE: no page-break-inside: avoid on .stylist — the service tables
           are taller than a page, and dompdf pushes an unbreakable block to
           the next page, which left page 1 nearly empty. Tables flow naturally. */
        .stylist { margin-top: 18px; }
        .stylist-newpage { page-break-before: always; }
        .stylist-photo { width: 92px; }
        .stylist-photo img { width: 88px; height: 88px; border: 1px solid #e4ddce; }
        /* No photo → the first letter, centred in a bordered box the same
           size as a photo (table-cell centring — reliable in dompdf). */
        table.ph-box { width: 88px; height: 88px; border-collapse: collapse; border: 1px solid #e4ddce; background: #faf7f0; }
        table.ph-box td { text-align: center; vertical-align: middle; font-family: 'DejaVu Serif', serif; font-size: 34px; color: #9a7b53; }
        .stylist-name { margin: 0; font-family: 'DejaVu Serif', serif; font-size: 16px; color: #201e1b; }
        .stylist-bio { margin: 3px 0 0; font-size: 9.5px; color: #4a4740; }
        .section-title { margin: 30px 0 4px; font-family: 'DejaVu Serif', serif; font-size: 19px; color: #201e1b; }
        .section-sub { margin: 0 0 6px; font-size: 9.5px; color: #928c7b; }
        h3.cat { margin: 20px 0 0; font-size: 11px; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; color: #9a7b53; }
        .group-name { margin: 10px 0 0; font-size: 10.5px; font-weight: bold; color: #201e1b; }
        table.split { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.split td.half { width: 50%; vertical-align: top; padding: 0; }
        table.split td.half-right { padding-left: 14px; }
        table.items { margin-top: 8px; }
    </style>
</head>
<body>
    @include('pdf.partials.header', [
        'docTitle' => 'Studio Brochure',
        'docRef' => ($salonName ?? 'DK StyleHub') . ' — our stylists',
        'badgeText' => 'OUR STYLISTS',
        'badgePaid' => true,
    ])

    <div class="content">
        <p class="section-title" style="margin-top: 0;">Our stylists</p>
        <p class="section-sub">Each stylist with the services they offer, at their own prices.</p>

        @forelse ($stylists as $stylist)
            {{-- Every stylist starts on a fresh page (never stranded at a page end). --}}
            <div class="stylist{{ $loop->first ? '' : ' stylist-newpage' }}">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td class="stylist-photo" style="vertical-align: top;">
                            @if (! empty($stylist['photo']))
                                <img src="{{ $stylist['photo'] }}" alt="{{ $stylist['name'] }}">
                            @else
                                <table class="ph-box"><tr><td>{{ $stylist['initial'] }}</td></tr></table>
                            @endif
                        </td>
                        <td style="vertical-align: top; padding-left: 12px;">
                            <p class="stylist-name">{{ $stylist['name'] }}</p>
                            @if (! empty($stylist['bio']))
                                <p class="stylist-bio">{{ $stylist['bio'] }}</p>
                            @endif
                        </td>
                    </tr>
                </table>
                @if (count($stylist['rows'] ?? []) > 0)
                    <table class="split">
                        <tr>
                            <td class="half">
                                <h3 class="cat" style="margin-top: 14px;">{{ $stylist['leftLabel'] }}</h3>
                            </td>
                            <td class="half half-right">
                                @if ($stylist['both'])
                                    <h3 class="cat" style="margin-top: 14px;">Women</h3>
                                @endif
                            </td>
                        </tr>
                        @foreach ($stylist['rows'] as $row)
                            <tr>
                                <td class="half">
                                    @if ($row['left'])
                                        @include('pdf.partials.brochure-gender', ['block' => ['groups' => [$row['left']]]])
                                    @endif
                                </td>
                                <td class="half half-right">
                                    @if ($row['right'])
                                        @include('pdf.partials.brochure-gender', ['block' => ['groups' => [$row['right']]]])
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </table>
                @else
                    <p class="note">Services for {{ $stylist['name'] }} are being updated — ask in the studio.</p>
                @endif
            </div>
        @empty
            <p class="note">Our stylist line-up is being updated — ask in the studio.</p>
        @endforelse

        <div class="thanks">
            <div class="rule"></div>
            <div class="line1">Walk in, or book online — we keep your chair ready.</div>
            <div class="line2">{{ $salonName ?? 'DK StyleHub' }}</div>
        </div>
    </div>

    @include('pdf.partials.footer', [
        'docRef' => ($salonName ?? 'DK StyleHub') . ' studio brochure',
        'terms' => 'Prices confirmed in the studio before your appointment.',
    ])
</body>
</html>
