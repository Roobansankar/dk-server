{{--
    The right-hand column beside the payment summary: only the Google
    review nudge (when a review URL is configured).

    Needs: optional $googleReviewUrl (string|null).
--}}
@if (! empty($googleReviewUrl))
    <div class="box box-follow">
        <p><span class="k">&#9733;</span> Enjoyed your visit? Leave us a Google review</p>
    </div>
@endif
