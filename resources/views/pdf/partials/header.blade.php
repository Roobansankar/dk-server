{{--
    Top of every bill: a dark band carrying the DK StyleHub mark (the light
    version, as on the site's own dark footer) and tagline on the left; the
    document title, its number and the payment badge on the right.

    Needs: $docTitle, $docRef, $badgeText, $badgePaid (bool);
    optional: $salonName (from the including view).
--}}
@php $logo = \App\Support\PdfLogo::lightDataUri(); @endphp
<div class="top">
<div class="band">
    <table>
        <tr>
            <td>
                @if ($logo !== '')
                    <img class="logo" src="{{ $logo }}" alt="{{ $salonName ?? 'DK StyleHub' }}">
                @else
                    <div class="doc-title" style="text-align: left; font-size: 20px;">{{ $salonName ?? 'DK StyleHub' }}</div>
                @endif
                <div class="tagline">Grooming &bull; Style &bull; Confidence</div>
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
