<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A review shown on the public homepage — either a Google review staff typed
 * in manually (no scraping/API sync), or one a signed-in customer submitted
 * directly through the website. Both share this one table/architecture and
 * the same moderation flag: `is_published` gates what the public `/reviews`
 * endpoint returns, so a customer submission (created with it `false`) only
 * ever reaches the homepage once an admin approves it here — exactly the
 * same act that already publishes a manually entered Google review.
 */
class Review extends Model
{
    use HasFactory, SoftDeletes;

    public const SOURCE_GOOGLE = 'google';

    public const SOURCE_CUSTOMER = 'customer';

    protected $fillable = [
        'user_id',
        'source',
        'reviewer_name',
        'rating',
        'review_text',
        'review_date',
        'reviewer_avatar_path',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'review_date' => 'date',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
