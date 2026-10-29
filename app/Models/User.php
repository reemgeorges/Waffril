<?php

namespace App\Models;

use App\Models\BaseAuthModel;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends BaseAuthModel
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        // Phase 1 (2026-10-25): is_active added by the additive migration
        // database/migrations/2026_10_25_120000_add_lifecycle_columns_to_users_tests_categories_tables.php
        'is_active' => 'is_active',
        // Phase 2A (2026-10-25): auth columns added by
        // database/migrations/2026_10_25_130200_add_auth_columns_to_users_table.php
        'status' => 'status',
        'social_id' => 'social_id',
        'prefix_phone' => 'prefix_phone',
        'phone_number' => 'phone_number',
        'otp_delivery_method' => 'otp_delivery_method',
        'email_status' => 'email_status',
        'phone_status' => 'phone_status',
        'language' => 'language',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'status' => 'boolean',
        'email_status' => 'boolean',
        'phone_status' => 'boolean',
        'activated_at' => 'datetime',
        'phone_verified_at' => 'datetime',
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
}
