{{--
    Top of every bill: the accent strip and the DK StyleHub logo (dark, as on the
    website's navbar) with the salon's contact line on the left; the document
    title, its number and the payment badge on the right.

    Needs: $docTitle, $docRef, $badgeText, $badgePaid (bool);
    optional: $salonName, $salonPhone, $salonAddress (from the including view).
--}}
@php $logo = \App\Support\PdfLogo::dataUri(); @endphp
<div class="top">
<div class="strip"></div>
<div class="band">
    <table>
        <tr>
            <td>
                @if ($logo !== '')
                    <img class="logo" src="{{ $logo }}" alt="{{ $salonName ?? 'DK StyleHub' }}">
                @else
                    <div class="doc-title" style="text-align: left; font-size: 20px;">{{ $salonName ?? 'DK StyleHub' }}</div>
                @endif
                @if (! empty($salonPhone) || ! empty($salonAddress))
                    <div class="contact">
                        @if (! empty($salonPhone)){{ $salonPhone }}@endif
                        @if (! empty($salonPhone) && ! empty($salonAddress))<br>@endif
                        @if (! empty($salonAddress)){{ $salonAddress }}@endif
                    </div>
                @endif
            </td>
            <td class="right">
                <div class="doc-title">{{ $docTitle }}</div>
                <div class="doc-ref">{{ $docRef }}</div>
                <span class="badge {{ $badgePaid ? '' : 'pending' }}">{{ $badgeText }}</span>
            </td>
        </tr>
    </table>
</div>
</div>
