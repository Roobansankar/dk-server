<?php

namespace App\Support;

use App\Models\CatalogueImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The photos of a product or a combo — at most MAX, in display order. The first
 * one is the cover: it is mirrored into the owner's `image_path`, so every
 * place that shows "the" image (cards, search, order emails, SEO) keeps working.
 *
 * What a save request may send (all optional; nothing sent = photos untouched):
 *
 *   images[]       new files to add
 *   image_order[]  the FINAL line-up, in order — "e:<id>" keeps an existing photo,
 *                  "n:<index>" places the index-th uploaded file; existing photos
 *                  left out are removed
 *   remove_image   1 → remove every photo (then add any new files)
 *   image          legacy single upload: replaces all photos with this one
 *
 * Without `image_order`, new files are simply added after the existing photos.
 */
class GalleryImages
{
    public const MAX = 4;

    /** Did this request try to change the photos at all? */
    public static function requested(Request $request): bool
    {
        return $request->hasFile('images')
            || $request->has('image_order')
            || $request->hasFile('image')
            || $request->boolean('remove_image');
    }

    /** @return array<int, UploadedFile> */
    public static function uploads(Request $request): array
    {
        return array_values(array_filter(Arr::wrap($request->file('images')), fn ($file) => $file instanceof UploadedFile));
    }

    /** Apply the request's photo changes to $owner and keep its cover in step. */
    public static function apply(Model $owner, Request $request, string $directory): void
    {
        if (! self::requested($request)) {
            return;
        }

        $existing = $owner->images()->get()->keyBy('id');
        $files = self::uploads($request);

        // The final line-up: photos to keep (existing rows) and files to store (new).
        if ($request->has('image_order')) {
            $plan = [];
            foreach ((array) $request->input('image_order') as $token) {
                [$kind, $ref] = array_pad(explode(':', (string) $token, 2), 2, '');

                if ($kind === 'e' && $existing->has((int) $ref)) {
                    $plan[] = ['keep' => $existing->get((int) $ref)];
                } elseif ($kind === 'n' && isset($files[(int) $ref])) {
                    $plan[] = ['new' => $files[(int) $ref]];
                }
            }
        } elseif ($request->hasFile('image')) {
            $plan = [['new' => $request->file('image')]];
        } else {
            $plan = $request->boolean('remove_image')
                ? []
                : $existing->values()->map(fn (CatalogueImage $image) => ['keep' => $image])->all();

            foreach ($files as $file) {
                $plan[] = ['new' => $file];
            }
        }

        if (count($plan) > self::MAX) {
            throw ValidationException::withMessages(['images' => self::tooMany()]);
        }

        $keptIds = collect($plan)->map(fn (array $step) => isset($step['keep']) ? $step['keep']->id : null)->filter()->all();
        $removed = $existing->reject(fn (CatalogueImage $image) => in_array($image->id, $keptIds, true));

        // Files are written first; if anything after that fails they are taken back.
        $stored = [];

        try {
            foreach ($plan as $position => $step) {
                if (isset($step['new'])) {
                    $plan[$position]['path'] = $stored[] = ImageUploader::store($step['new'], $directory);
                }
            }

            DB::transaction(function () use ($owner, $plan, $removed) {
                $removed->each->delete();

                foreach ($plan as $position => $step) {
                    if (isset($step['keep'])) {
                        $step['keep']->update(['sort_order' => $position]);
                    } else {
                        $owner->images()->create(['path' => $step['path'], 'sort_order' => $position]);
                    }
                }

                $first = $plan[0] ?? null;

                $owner->forceFill(['image_path' => $first ? ($first['keep']->path ?? $first['path']) : null])->saveQuietly();
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                ImageUploader::delete($path);
            }

            throw $e;
        }

        // Only once the change is saved: the photos that were removed lose their files.
        $removed->each(fn (CatalogueImage $image) => ImageUploader::delete($image->path));
        $owner->unsetRelation('images');
    }

    /** Remove every photo of $owner (rows and files) — used when the item itself is deleted. */
    public static function purge(Model $owner): void
    {
        $images = $owner->images()->get();

        $images->each(fn (CatalogueImage $image) => ImageUploader::delete($image->path));
        $owner->images()->delete();

        ImageUploader::delete($owner->image_path);
        $owner->image_path = null;
        $owner->saveQuietly();
        $owner->unsetRelation('images');
    }

    public static function tooMany(): string
    {
        return 'You can add up to '.self::MAX.' photos. Remove one to add another.';
    }
}
