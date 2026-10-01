<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const TYPE_STAFF = 'staff';

    /**
     * Legacy: customer accounts can no longer be created or signed in to.
     * Rows of this type may still exist; `type` keeps them out of the staff
     * user-management screens (see scopeStaff / isStaff).
     */
    public const TYPE_CUSTOMER = 'customer';

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'type',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuperadmin(): bool
    {
        return $this->hasRole(Role::SUPERADMIN);
    }

    public function isStaff(): bool
    {
        return $this->type === self::TYPE_STAFF;
    }

    public function scopeStaff($query)
    {
        return $query->where('type', self::TYPE_STAFF);
    }
}
