<?php

namespace App\Support;

use App\Models\SiteSetting;
use App\Models\Stylist;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

/**
 * The studio brochure PDF behind the QR code on the public Contact page:
 * every active stylist (photo, or a name-initial placeholder when they have
 * none) with the services THEY offer. Public and cache-free — it always
 * reflects the live catalogue.
 */
class StudioBrochurePdf
{
    public static function make(): DomPdf
    {
        $settings = SiteSetting::allValues();

        $stylists = self::stylistsWithServices()
            ->map(function (array $entry) {
                ['stylist' => $stylist, 'men' => $men, 'women' => $women] = $entry;

                // One table row per index (men left, women right) so both
                // columns pack with no empty gaps AND rows stay short enough
                // to break across pages — a single giant row never fits and
                // leaves a gap. PDF-layout-only, so it's built here rather
                // than in stylistsWithServices() (the JSON API has no need
                // for it — a webpage just stacks the two lists).
                $rows = [];
                if (count($men) > 0 && count($women) > 0) {
                    $n = max(count($men), count($women));
                    for ($i = 0; $i < $n; $i++) {
                        $rows[] = ['left' => $men[$i] ?? null, 'right' => $women[$i] ?? null];
                    }
                } else {
                    foreach (array_merge($men, $women) as $g) {
                        $rows[] = ['left' => $g, 'right' => null];
                    }
                }
                $leftLabel = count($men) > 0 ? 'Men' : (count($women) > 0 ? 'Women' : null);

                return [
                    'name' => $stylist->name,
                    'bio' => $stylist->bio,
                    'initial' => mb_strtoupper(mb_substr($stylist->name ?? '?', 0, 1)),
                    'photo' => self::photoUri($stylist->image_path),
                    'both' => count($men) > 0 && count($women) > 0,
                    'leftLabel' => $leftLabel,
                    'rows' => $rows,
                ];
            })
            ->all();

        return Pdf::loadView('pdf.studio-brochure', [
            'salonName' => $settings->get('salon_name', 'DK StyleHub'),
            'salonPhone' => $settings->get('phone'),
            'salonAddress' => $settings->get('address'),
            'instagramUrl' => $settings->get('instagram_url'),
            'googleReviewUrl' => $settings->get('google_review_url'),
            'logo' => PdfLogo::dataUri(),
            'stylists' => $stylists,
            'generatedAt' => now(BookingAvailability::TZ),
        ])->setPaper('a4');
    }

    public static function filename(): string
    {
        return 'dk-stylehub-studio-brochure.pdf';
    }

    /**
     * Every active stylist with their services grouped men → women, each
     * category-wise and deduped (gender variants share a service name).
     * Shared by the PDF above and the JSON brochure page data — each shapes
     * this differently from here (the PDF pairs rows into print columns and
     * embeds a downscaled photo; the web page just wants a plain image URL).
     *
     * @return \Illuminate\Support\Collection<int, array{stylist: Stylist, men: array, women: array}>
     */
    public static function stylistsWithServices(): \Illuminate\Support\Collection
    {
        return Stylist::query()
            ->active()
            ->ordered()
            ->with([
                'services' => fn ($q) => $q->where('services.status', true)->ordered(),
                'services.category',
            ])
            ->get()
            ->map(function (Stylist $stylist) {
                $groups = ['male' => [], 'female' => []];
                foreach ($stylist->services as $service) {
                    $gender = $service->category?->gender ?? 'male';
                    if (! isset($groups[$gender])) {
                        continue;
                    }
                    $catName = $service->category?->name ?? 'Services';
                    $skey = mb_strtolower(trim($service->name));
                    if (isset($groups[$gender][$catName]['seen'][$skey])) {
                        continue;
                    }
                    $groups[$gender][$catName]['seen'][$skey] = true;
                    $groups[$gender][$catName]['services'][] = [
                        'name' => $service->name,
                        'price' => $service->pivot->price !== null
                            ? (float) $service->pivot->price
                            : ($service->price !== null ? (float) $service->price : null),
                    ];
                    $groups[$gender][$catName]['order'] ??= $service->category?->sort_order ?? 0;
                }
                $shape = function ($list) {
                    $groups = array_values(array_filter(
                        array_map(fn ($name, $g) => [
                            'category' => $name,
                            'order' => $g['order'],
                            'services' => $g['services'],
                        ], array_keys($list), array_values($list)),
                        fn ($g) => count($g['services']) > 0,
                    ));
                    usort($groups, fn ($a, $b) => [$a['order'], $a['category']] <=> [$b['order'], $b['category']]);

                    return $groups;
                };

                return [
                    'stylist' => $stylist,
                    'men' => $shape($groups['male']),
                    'women' => $shape($groups['female']),
                ];
            });
    }

    /**
     * Stylist photo as a small JPEG data URI (dompdf chokes on huge/webp data
     * URIs, so photos are downscaled), or null → initial placeholder.
     */
    private static function photoUri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        try {
            $disk = Storage::disk(config('salon.media_disk', 'public'));
            if (! $disk->exists($path)) {
                return null;
            }
            $bytes = $disk->get($path);
            $small = self::shrinkToJpeg($bytes);

            return 'data:image/jpeg;base64,'.base64_encode($small ?? $bytes);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Downscale raw image bytes to a ~360px JPEG for the PDF, or null when GD can't read them. */
    private static function shrinkToJpeg(string $bytes, int $maxEdge = 360): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxEdge / max(1, max($w, $h)));
        $dst = $scale < 1 ? @imagescale($src, (int) round($w * $scale), (int) round($h * $scale)) : $src;
        if ($dst === false) {
            imagedestroy($src);

            return null;
        }
        ob_start();
        $ok = imagejpeg($dst, null, 72);
        $out = ob_get_clean();
        imagedestroy($src);
        if ($dst !== $src) {
            imagedestroy($dst);
        }

        return $ok && $out !== '' ? $out : null;
    }
}
