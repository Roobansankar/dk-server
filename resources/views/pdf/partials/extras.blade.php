{{--
    The right-hand column beside the payment summary: an optional "next visit"
    nudge, the loyalty callout, and how to find the studio online.

    Needs: $salonName; optional: $nextVisitNote (string|null — omitted for
    product orders), $instagramHandle (string|null), $googleReviewUrl (string|null).
--}}
@if (! empty($nextVisitNote))
    <div class="box box-light">
        <span class="box-title">Your next look starts here</span>
        {{ $nextVisitNote }}
    </div>
@endif

<div class="box box-dark">
    <span class="box-title">{{ $salonName ?? 'DK StyleHub' }} Privilege</span>
    Collect your visits &amp; unlock exclusive benefits.
</div>

@if (! empty($instagramHandle) || ! empty($googleReviewUrl))
    <div class="box box-follow">
        @if (! empty($instagramHandle))
            <p><span class="k">IG</span> {{ '@' . $instagramHandle }} on Instagram</p>
        @endif
        @if (! empty($googleReviewUrl))
            <p><span class="k">&#9733;</span> Enjoyed your visit? Leave us a Google review</p>
        @endif
    </div>
@endif
