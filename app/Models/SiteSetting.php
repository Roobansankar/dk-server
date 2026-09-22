<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group'];

    public $timestamps = true;

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public const CACHE_KEY = 'site_settings.all';

    /** @return Collection<string, mixed> */
    public static function allValues(): Collection
    {
        // Cache a plain array (not a Collection object) so every cache backend
        // can round-trip it safely; wrap it for the Collection API on the way out.
        $values = Cache::rememberForever(self::CACHE_KEY, function () {
            return static::all()
                ->mapWithKeys(fn (SiteSetting $s) => [$s->key => $s->castValue()])
                ->all();
        });

        return collect($values);
    }

    public function castValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
