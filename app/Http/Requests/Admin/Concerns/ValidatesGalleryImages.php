<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Support\GalleryImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * Validation for the multi-photo fields of a product / combo save request
 * (see App\Support\GalleryImages for what they mean).
 */
trait ValidatesGalleryImages
{
    /** @return array<string, array<int, string>> */
    protected function galleryRules(): array
    {
        return [
            'images' => ['sometimes', 'array', 'max:'.GalleryImages::MAX],
            'images.*' => [
                'file', 'image',
                'mimes:'.implode(',', config('salon.uploads.mimes')),
                'max:'.config('salon.uploads.max_kb'),
            ],
            'image_order' => ['sometimes', 'array', 'max:'.GalleryImages::MAX],
            'image_order.*' => ['string', 'regex:/^[en]:\d{1,9}$/', 'distinct'],
        ];
    }

    /**
     * The checks that need more than one field: every "e:" points at one of THIS
     * item's photos, every uploaded file is placed exactly once, and the total
     * stays within the limit.
     */
    protected function checkGallery(Validator $validator, ?Model $owner): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $uploads = count(GalleryImages::uploads($this));
        $existing = $owner ? $owner->images()->pluck('id')->map(fn ($id) => (int) $id)->all() : [];

        if ($this->has('image_order')) {
            $placed = 0;

            foreach ((array) $this->input('image_order') as $i => $token) {
                [$kind, $ref] = explode(':', (string) $token, 2);

                if ($kind === 'e' && ! in_array((int) $ref, $existing, true)) {
                    $validator->errors()->add("image_order.$i", 'That photo does not belong to this item.');
                } elseif ($kind === 'n') {
                    $placed++;

                    if ((int) $ref >= $uploads) {
                        $validator->errors()->add("image_order.$i", 'That new photo was not uploaded.');
                    }
                }
            }

            if ($placed !== $uploads) {
                $validator->errors()->add('images', 'Every uploaded photo must be placed in the order.');
            }

            return;
        }

        $kept = $this->boolean('remove_image') || $this->hasFile('image') ? 0 : count($existing);

        if ($kept + $uploads > GalleryImages::MAX) {
            $validator->errors()->add('images', GalleryImages::tooMany());
        }
    }
}
