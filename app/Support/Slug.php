<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Slug
{
    /**
     * Build a slug from $source that is unique for the given query scope.
     *
     * @param  callable(Builder):Builder|null  $scope  extra constraints (e.g. same gender/category)
     */
    public static function unique(string $model, string $source, string $column = 'slug', ?callable $scope = null, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $i = 2;

        while (true) {
            /** @var Builder $query */
            $query = $model::query()->where($column, $slug);

            if ($scope) {
                $scope($query);
            }

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }

            if (method_exists($model, 'bootSoftDeletes')) {
                $query->withTrashed();
            }

            if (! $query->exists()) {
                return $slug;
            }

            $slug = "{$base}-{$i}";
            $i++;
        }
    }
}
