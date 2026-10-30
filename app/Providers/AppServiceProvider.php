<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use App\Services\Functional\AdminAuthService;

use App\Services\Functional\UserAuthService;
use App\Exceptions\Handler;

class AppServiceProvider extends ServiceProvider
{
   protected $facades = [
    'CategoryService' => \App\Services\Model\Category\CategoryService::class,

    'TestService' => \App\Services\Model\Test\TestService::class,

    'UserService' => \App\Services\Model\User\UserService::class,

    



    'AdminAuthService' => AdminAuthService::class,

    'UserAuthService' => UserAuthService::class,

    // WAFFRLI P0 Foundation (2026-10-30)
    'SettingManagementService' => \App\Services\Model\Setting\SettingManagementService::class,

    'CurrencyService' => \App\Services\Model\Currency\CurrencyService::class,

    'CityService' => \App\Services\Model\City\CityService::class,

    'DeliveryAreaService' => \App\Services\Model\DeliveryArea\DeliveryAreaService::class,

    'DeliveryFeeRuleService' => \App\Services\Model\DeliveryFeeRule\DeliveryFeeRuleService::class,

     ];
    /**
     * Register any application services.
     */
    public function register(): void
    {
        foreach ($this->facades as $facade => $service) {
            $this->app->singleton($facade, function ($app) use ($service) {
                return $app->make($service);
            });
        }

        $this->app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, Handler::class);

        // WAFFRLI P0 (2026-10-30): replaceable delivery-distance strategy,
        // chosen by config('delivery.distance_strategy'). Haversine is a
        // provisional technical assumption, not a business rule.
        $this->app->bind(\App\Contracts\Delivery\DistanceCalculator::class, function ($app) {
            $strategy = config('delivery.distance_strategy', 'haversine');
            $class = config("delivery.distance_strategies.{$strategy}");

            if (! $class) {
                throw new \InvalidArgumentException("Unknown delivery distance strategy [{$strategy}].");
            }

            return $app->make($class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthRateLimiters();
    }

    /**
     * Phase 3C (2026-10-25) — endpoint rate limiting. Before this, every
     * login/register/reset/phone/email-change route had NO HTTP-layer
     * throttle at all — App\Services\Auth\OtpService's own rate limits
     * only apply once a request reaches the OTP engine, so password-guess
     * brute forcing against login() (which never touches OTP) was
     * completely unthrottled. Keyed by IP + the submitted identifier
     * (email/phone), not IP alone, so one attacker can't exhaust a single
     * victim's budget from many IPs, nor one noisy IP block every other
     * user behind it (e.g. shared NAT/corporate egress).
     */
    protected function registerAuthRateLimiters(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            $identifier = (string) ($request->input('email') ?: $request->input('phone_number') ?: $request->input('social_id'));

            return Limit::perMinute(10)->by($request->ip().'|'.$identifier);
        });

        RateLimiter::for('auth-otp-endpoint', function (Request $request) {
            $identifier = (string) ($request->input('email') ?: $request->input('phone_number') ?: $request->input('id'));

            return Limit::perMinute(5)->by($request->ip().'|'.$identifier);
        });
    }
}
