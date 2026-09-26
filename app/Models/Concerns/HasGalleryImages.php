<?php

namespace App\Models\Concerns;

use App\Models\CatalogueImage;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A model with up to four photos (see App\Support\GalleryImages), in display order. */
trait HasGalleryImages
{
    public function images(): MorphMany
    {
        return $this->morphMany(CatalogueImage::class, 'imageable')->orderBy('sort_order')->orderBy('id');
    }
}
