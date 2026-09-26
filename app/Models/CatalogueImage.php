<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One photo of a product or a combo. Written only through
 * App\Support\GalleryImages, which enforces the four-photo limit and keeps the
 * owner's cover (`image_path`) equal to its first photo.
 */
class CatalogueImage extends Model
{
    protected $fillable = [
        'path',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}
