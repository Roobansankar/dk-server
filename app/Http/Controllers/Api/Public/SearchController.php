<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ServiceResource;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\Request;

/**
 * Global search across the live services + products catalogue. Reads
 * straight from the database with the same "active only" rule the public
 * /services and /products endpoints already apply, so a newly published
 * admin product is searchable the instant it's active, and an unpublished
 * or deleted one drops out immediately — nothing here is cached.
 */
class SearchController extends Controller
{
    private const LIMIT = 8;

    // '!' as the LIKE ESCAPE character (rather than the more conventional
    // '\') sidesteps a real MySQL-vs-SQLite divide: MySQL unescapes `\\`
    // inside a single-quoted string literal to one backslash before SQL even
    // sees it, SQLite does not — so the same raw ESCAPE '\\' clause means two
    // different things (and errors on SQLite, which this app's test suite
    // runs on). '!' needs no such doubling on either engine.
    private const ESCAPE_CHAR = '!';

    public function index(Request $request)
    {
        // `nullable` matters here, not just cosmetically: an explicitly empty
        // query value (`?q=`) is normalized to `null` (not `''`) by the time
        // it reaches validation, and `sometimes` alone still treats a present
        // `null` key as needing the `string` rule, which then fails it.
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));

        if ($term === '') {
            return response()->json(['services' => [], 'products' => []]);
        }

        $like = self::likeTerm($term);

        $services = Service::query()
            ->active()
            ->whereHas('category', fn ($q) => $q->active())
            ->with('category')
            ->where(function ($q) use ($like) {
                $q->whereRaw("name LIKE ? ESCAPE '".self::ESCAPE_CHAR."'", [$like])
                    ->orWhereRaw("description LIKE ? ESCAPE '".self::ESCAPE_CHAR."'", [$like])
                    ->orWhereHas('category', function ($cat) use ($like) {
                        $cat->whereRaw("name LIKE ? ESCAPE '".self::ESCAPE_CHAR."'", [$like]);
                    });
            })
            ->ordered()
            ->limit(self::LIMIT)
            ->get();

        $products = Product::query()
            ->active()
            ->where(function ($q) use ($like) {
                $q->whereRaw("name LIKE ? ESCAPE '".self::ESCAPE_CHAR."'", [$like])
                    ->orWhereRaw("description LIKE ? ESCAPE '".self::ESCAPE_CHAR."'", [$like]);
            })
            ->ordered()
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'services' => ServiceResource::collection($services),
            'products' => ProductResource::collection($products),
        ]);
    }

    /** Escape SQL LIKE wildcards in user input so `%`/`_` are matched literally. */
    private static function likeTerm(string $term): string
    {
        $escaped = str_replace(
            [self::ESCAPE_CHAR, '%', '_'],
            [self::ESCAPE_CHAR.self::ESCAPE_CHAR, self::ESCAPE_CHAR.'%', self::ESCAPE_CHAR.'_'],
            $term,
        );

        return '%'.$escaped.'%';
    }
}
