<?php

namespace App\Providers;

use App\Contracts\Delivery\DistanceCalculator;
use App\Contracts\Messaging\TextMessageGateway;
use App\Exceptions\Handler;
use App\Policies\NotificationPolicy;
use App\Policies\RolePolicy;
use App\Services\Functional\AdminAuthService;
use App\Services\Functional\UserAuthService;
use App\Services\Messaging\TextMessenger;
use App\Services\Model\Category\CategoryService;
use App\Services\Model\City\CityService;
use App\Services\Model\Currency\CurrencyService;
use App\Services\Model\DeliveryArea\DeliveryAreaService;
use App\Services\Model\DeliveryFeeRule\DeliveryFeeRuleService;
use App\Services\Model\Setting\SettingManagementService;
use App\Services\Model\Test\TestService;
use App\Services\Model\User\UserService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    protected $facades = [
        'CategoryService' => CategoryService::class,

        'TestService' => TestService::class,

        'UserService' => UserService::class,

        'AdminAuthService' => AdminAuthService::class,

        'UserAuthService' => UserAuthService::class,

        // WAFFRLI P0 Foundation (2026-10-30)
        'SettingManagementService' => SettingManagementService::class,

        'CurrencyService' => CurrencyService::class,

        'CityService' => CityService::class,

        'DeliveryAreaService' => DeliveryAreaService::class,

        'DeliveryFeeRuleService' => DeliveryFeeRuleService::class,

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

        $this->app->singleton(ExceptionHandler::class, Handler::class);

        // WAFFRLI customer identity (2026-11-03): OTP text transport (fake | twilio).
        $this->app->bind(TextMessageGateway::class, fn () => TextMessenger::gatewayFor((string) config('waffrli.messaging.driver', 'fake')));

        // WAFFRLI P0 (2026-10-30): replaceable delivery-distance strategy,
        // chosen by config('delivery.distance_strategy'). Haversine is a
        // provisional technical assumption, not a business rule.
        $this->app->bind(DistanceCalculator::class, function ($app) {
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

        // WAFFRLI (2026-11-03): domain events → realtime operations alerts.
        \Illuminate\Support\Facades\Event::subscribe(\App\Listeners\RealtimeDomainSubscriber::class);

        // WAFFRLI (2026-11-03): in-memory broadcaster used by automated tests.
        Broadcast::extend('recording', fn ($app, array $config) => new \App\Support\Broadcasting\RecordingBroadcaster(
            Broadcast::pusher($config)
        ));

        // WAFFRLI (2026-11-03): private channels of the ONE Reverb server. The
        // auth endpoints (one per identity) are plain routes using
        // App\Http\Controllers\Realtime\BroadcastAuthController: see
        // routes/web.php (admin_web session) and routes/api/v1/{customer,driver}.php.
        require base_path('routes/channels.php');

        // WAFFRLI (2026-10-31): policies for vendor models used by the admin panel.
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(DatabaseNotification::class, NotificationPolicy::class);
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

        // WAFFRLI customer API (2026-11-03).
        RateLimiter::for('customer-otp', function (Request $request) {
            return [
                Limit::perMinute(5)->by('customer-otp-ip:'.$request->ip()),
                Limit::perMinute(3)->by('customer-otp-phone:'.(string) $request->input('phone')),
            ];
        });

        RateLimiter::for('customer-otp-verify', function (Request $request) {
            return Limit::perMinute(20)->by('customer-otp-verify-ip:'.$request->ip());
        });

        RateLimiter::for('customer-api', function (Request $request) {
            return Limit::perMinute(180)->by('customer:'.($request->user('user')?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('customer-checkout', function (Request $request) {
            return Limit::perMinute(10)->by('customer-checkout:'.($request->user('user')?->getKey() ?? $request->ip()));
        });

        // WAFFRLI driver API (2026-11-02).
        RateLimiter::for('driver-login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|driver|'.(string) $request->input('phone'));
        });

        RateLimiter::for('driver-api', function (Request $request) {
            return Limit::perMinute(120)->by('driver:'.($request->user('driver')?->getKey() ?? $request->ip()));
        });

        // GPS pings: roughly one every 5 s is plenty for the map.
        RateLimiter::for('driver-location', function (Request $request) {
            return Limit::perMinute(12)->by('driver-location:'.($request->user('driver')?->getKey() ?? $request->ip()));
        });

        RateLimiter::for('auth-otp-endpoint', function (Request $request) {
            $identifier = (string) ($request->input('email') ?: $request->input('phone_number') ?: $request->input('id'));

            return Limit::perMinute(5)->by($request->ip().'|'.$identifier);
        });
    }
}
