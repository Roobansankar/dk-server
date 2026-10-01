<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint — a visitor can leave a review without an account.
        return true;
    }

    /**
     * Deliberately no `is_published`/`source`/`user_id` field here — the
     * controller always stores a visitor's review unpublished and marked as
     * a customer submission, so a crafted request body can never
     * self-publish or pass itself off as a staff-entered Google review.
     */
    public function rules(): array
    {
        return [
            'reviewer_name' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review_text' => ['required', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reviewer_name' => 'name',
            'review_text' => 'review',
        ];
    }
}
