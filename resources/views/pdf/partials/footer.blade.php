{{--
    Bottom of every page: a dark band with the studio's social/contact line,
    then a thin Terms & Conditions note.

    Needs: $docRef (e.g. "bill APT-XXXX"); optional: $salonName, $salonPhone,
    $salonAddress, $instagramHandle, $terms (defaults to the standard service
    note), $generatedAt (a Carbon instance — when given, stamps the bill with
    when this exact copy was produced).
--}}
<div class="footer">
    <table>
        <tr>
            <td class="info">
                {{ collect([
                    ! empty($instagramHandle) ? '@'.$instagramHandle : null,
                    $salonAddress ?? null,
                    $salonPhone ?? null,
                ])->filter()->implode('    |    ') }}
            </td>
            <td class="script">Look Good, Feel Better</td>
        </tr>
    </table>
    <p class="terms">
        Terms &amp; Conditions: {{ $terms ?? 'Services once completed are non-refundable.' }}
        &nbsp;&mdash;&nbsp; {{ $salonName ?? 'DK StyleHub' }}, {{ $docRef }}
        @if (! empty($generatedAt))
            &nbsp;&mdash;&nbsp; generated {{ $generatedAt->format('d M Y H:i') }}
        @endif
    </p>
</div>
