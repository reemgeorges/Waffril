<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        // WAFFRLI (2026-10-31): approved customer identity columns — see
        // 2026_10_31_100000_add_waffrli_identity_columns_to_users_table.php.
        // account_status / suspension / deletion columns are deliberately NOT
        // mass assignable: only App\Services\Customer\CustomerAccountService
        // changes them.
        'first_name' => 'first_name',
        'avatar' => 'avatar',
        'last_name' => 'last_name',
        'phone' => 'phone',
        'otp_channel' => 'otp_channel',
        'display_currency_id' => 'display_currency_id',
    ];

    public const ACCOUNT_ACTIVE = 'active';

    public const ACCOUNT_SUSPENDED = 'suspended';

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'status' => 'boolean',
        'email_status' => 'boolean',
        'phone_status' => 'boolean',
        'activated_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'suspended_at' => 'datetime',
        'profile_completed_at' => 'datetime',
        'recovery_until' => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'verified_email',
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

    public function fullName(): string
    {
        $full = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $full !== '' ? $full : (string) $this->name;
    }

    public function isSuspended(): bool
    {
        return $this->account_status === self::ACCOUNT_SUSPENDED;
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function plusMembership(): HasOne
    {
        return $this->hasOne(PlusMembership::class);
    }

    public function plusRequests(): HasMany
    {
        return $this->hasMany(PlusRequest::class);
    }

    public function plusCycles(): HasMany
    {
        return $this->hasMany(PlusCycle::class);
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favorites')->withPivot('created_at');
    }

    public function productRatings(): HasMany
    {
        return $this->hasMany(ProductRating::class);
    }

    public function displayCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'display_currency_id');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'suspended_by_admin_id');
    }

    /** WAFFRLI (2026-11-03): live notifications on the private Reverb channel user.{id}. */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'user.'.$this->getKey();
    }
}
