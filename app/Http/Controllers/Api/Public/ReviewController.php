<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;

class ReviewController extends Controller
{
    /**
     * Published reviews only — a manually staff-entered Google review or an
     * approved customer submission look identical here; both are just rows
     * in the same table with `is_published = true`.
     */
    public function index()
    {
        return ReviewResource::collection(
            Review::query()->active()->ordered()->get()
        );
    }

    /**
     * A signed-in customer's own review. Never auto-published — it lands in
     * the same admin Reviews queue as a draft Google review and only reaches
     * the public endpoint once staff flips `is_published` there, same as
     * any other review.
     */
    public function store(StoreCustomerReviewRequest $request)
    {
        $review = Review::create([
            // Ownership and display name both come from the authenticated
            // account only — StoreCustomerReviewRequest exposes no
            // `user_id`/`reviewer_name` field, so neither can be spoofed.
            'user_id' => $request->user()->id,
            'source' => Review::SOURCE_CUSTOMER,
            'reviewer_name' => $request->user()->name,
            'rating' => $request->integer('rating'),
            'review_text' => $request->string('review_text'),
            'review_date' => now()->toDateString(),
            'is_published' => false,
        ]);

        return (new ReviewResource($review))
            ->additional(['message' => 'Thanks for your review! It’ll appear on the site once approved.'])
            ->response()
            ->setStatusCode(201);
    }
}
