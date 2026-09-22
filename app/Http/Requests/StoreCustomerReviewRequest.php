<?php

namespace App\Http\Requests;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Belt-and-braces alongside the route's `auth:sanctum` middleware —
        // a customer-submitted review always belongs to a signed-in account.
        return $this->user() !== null;
    }

    /**
     * Deliberately no `reviewer_name` (or `user_id`/`is_published`/`source`)
     * field here — the controller always takes the display name from the
     * authenticated account and derives ownership from the bearer token, so
     * a crafted request body can never impersonate another customer or
     * self-publish.
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review_text' => ['required', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // One review per customer — a simple, obvious spam/duplicate
            // guard (mirrors the "check, then friendly-error" pattern used
            // elsewhere, e.g. StoreAppointmentRequest's conflict check)
            // rather than a new moderation queue or rate-limit scheme.
            if (Review::where('user_id', $this->user()->id)->exists()) {
                $validator->errors()->add(
                    'review_text',
                    'You’ve already submitted a review. Thank you for sharing your feedback!',
                );
            }
        });
    }
}
