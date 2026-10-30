<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * WAFFRLI (2026-10-31): also the Filament admin-panel user (session guard
 * `admin_web`, same `admins` table and Spatie roles/permissions as the
 * `admin` Sanctum guard). Only active, non-deleted admins may enter.
 */
class Admin extends BaseAuthModel implements FilamentUser
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    /** @use HasFactory<AdminFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * WAFFRLI (2026-10-31): roles/permissions always live on the 'admin'
     * guard. Without this, Spatie infers the guard from the active default,
     * which becomes 'admin_web' after a Filament session login.
     */
    protected string $guard_name = 'admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name' => 'name',
        'email' => 'email',
        'email_verified_at' => 'email_verified_at',
        'password' => 'password',
        'remember_token' => 'remember_token',
        // Phase 2A (2026-10-25): lifecycle column, see
        // database/migrations/2026_10_25_130000_create_admins_table.php
        'is_active' => 'is_active',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active && ! $this->trashed();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN_ROLE, 'admin');
    }

    /** WAFFRLI (2026-11-03): live notifications on the private Reverb channel admin.{id}. */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'admin.'.$this->getKey();
    }
}
