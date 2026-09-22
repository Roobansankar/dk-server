<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceCategory extends Model
{
    use HasFactory, SoftDeletes;

    public const GENDER_MALE = 'male';

    public const GENDER_FEMALE = 'female';

    public const GENDERS = [self::GENDER_MALE, self::GENDER_FEMALE];

    public const TYPE_HAIR = 'hair';

    public const TYPE_SKIN = 'skin';

    public const TYPES = [self::TYPE_HAIR, self::TYPE_SKIN];

    protected $fillable = [
        'gender',
        'name',
        'slug',
        'description',
        'category_type',
        'image_path',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeGender($query, string $gender)
    {
        return $query->where('gender', $gender);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('category_type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
