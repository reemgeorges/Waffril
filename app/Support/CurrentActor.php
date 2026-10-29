<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class CurrentActor
{
    public static function admin(): ?Admin
    {
        return Auth::guard('admin')->user();
    }

    public static function user(): ?User
    {
        return Auth::guard('user')->user()
            ?? Auth::guard('web')->user()
            ?? Auth::user();
    }

    public static function isAdmin(): bool
    {
        return self::admin() instanceof Admin;
    }

    public static function isUser(): bool
    {
        return self::user() instanceof User;
    }
}
