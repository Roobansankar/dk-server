<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderRequest;
use App\Http\Requests\Admin\StoreReviewRequest;
use App\Http\Requests\Admin\UpdateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $reviews = Review::query()
            ->when($request->filled('is_published'), fn ($q) => $q->where('is_published', $request->boolean('is_published')))
            ->ordered()
            ->paginate($request->integer('per_page', 30));

        return ReviewResource::collection($reviews);
    }

    public function store(StoreReviewRequest $request)
    {
        $data = $request->safe()->except(['avatar']);

        if ($request->hasFile('avatar')) {
            $data['reviewer_avatar_path'] = ImageUploader::store($request->file('avatar'), 'reviews');
        }

        $review = Review::create($data);

        return (new ReviewResource($review))->response()->setStatusCode(201);
    }

    public function show(Review $review)
    {
        return new ReviewResource($review);
    }

    public function update(UpdateReviewRequest $request, Review $review)
    {
        $data = $request->safe()->except(['avatar']);

        if ($request->hasFile('avatar')) {
            ImageUploader::delete($review->reviewer_avatar_path);
            $data['reviewer_avatar_path'] = ImageUploader::store($request->file('avatar'), 'reviews');
        }

        $review->update($data);

        return new ReviewResource($review);
    }

    public function destroy(Review $review)
    {
        ImageUploader::delete($review->reviewer_avatar_path);
        $review->forceDelete();

        return response()->noContent();
    }

    public function reorder(ReorderRequest $request)
    {
        DB::transaction(function () use ($request) {
            foreach ($request->array('ids') as $position => $id) {
                Review::whereKey($id)->update(['sort_order' => $position]);
            }
        });

        return response()->json(['message' => 'Order updated.']);
    }
}
