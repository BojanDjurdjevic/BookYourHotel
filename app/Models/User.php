<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->getOriginal('is_demo_sandbox') && $user->isDirty([
                'email', 'password', 'role', 'is_demo_sandbox', 'supplier_deactivated_at',
            ])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'demo' => 'The shared demo account credentials and access cannot be changed.',
                ]);
            }
        });
        static::deleting(function (User $user) {
            abort_if($user->is_demo_sandbox, 403, 'The shared demo account cannot be deleted.');
        });
    }

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

  
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_demo_sandbox' => 'boolean',
            'supplier_deactivated_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    const ROLE_SUPERADMIN = 'superadmin';
    const ROLE_ADMIN = 'admin';
    const ROLE_SUPPLIER = 'supplier';
    const ROLE_USER = 'user';

    public function scopeSuppliers($query)
    {
        return $query->where('role', 'supplier');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN || $this->role === self::ROLE_ADMIN;
    }

    public function isSupplier(): bool
    {
        return $this->role === self::ROLE_SUPPLIER && $this->supplier_deactivated_at === null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function hotels()
    {
        return $this->hasMany(Hotel::class, 'supplier_id', 'id');
    }
}
