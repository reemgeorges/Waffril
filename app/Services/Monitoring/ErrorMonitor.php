<?php

namespace App\Services\Monitoring;

use App\Exceptions\BusinessRuleException;
use App\Models\ErrorEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * WAFFRLI (2026-11-16, Phase 9) — stores unexpected application errors,
 * grouped, for the admin panel (Monitoring → Errors).
 *
 * - Registered as an exception reporter (AppServiceProvider): normal
 *   logging is unchanged; this only adds a grouped, scrubbed record.
 * - Expected outcomes are not errors: business rules, validation,
 *   authentication/authorization, missing records, CSRF, throttling and
 *   any HTTP status below 500.
 * - Grouping key: exception class + first application frame + message with
 *   numbers/quoted values normalised.
 * - Stored: scrubbed message (Scrubber), a trace of file:line + function
 *   only (never arguments), the request id, method, path (no query
 *   string), route and actor type:id. Never request bodies or headers.
 * - A resolved error that happens again is reopened and marked regressed;
 *   an ignored one keeps counting silently.
 * - Never throws: a failure here must not hide the original error.
 */
final class ErrorMonitor
{
    public const EXPECTED = [
        BusinessRuleException::class,
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        TokenMismatchException::class,
        ThrottleRequestsException::class,
    ];

    private static bool $capturing = false;

    public static function capture(Throwable $e): void
    {
        if (self::$capturing || ! config('waffrli.monitoring.errors_enabled', true) || self::expected($e)) {
            return;
        }

        self::$capturing = true;
        try {
            self::store($e);
        } catch (Throwable) {
            // The original exception is still logged by Laravel.
        } finally {
            self::$capturing = false;
        }
    }

    public static function expected(Throwable $e): bool
    {
        foreach (self::EXPECTED as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
    }

    /** @return array{0: string, 1: int} the first frame inside the application (not vendor), else the throw site */
    public static function location(Throwable $e): array
    {
        $frames = array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace());
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if ($file && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                return [self::relative($file), (int) ($frame['line'] ?? 0)];
            }
        }

        return [self::relative($e->getFile()), $e->getLine()];
    }

    public static function fingerprint(Throwable $e): string
    {
        [$file, $line] = self::location($e);

        return hash('sha256', get_class($e).'|'.$file.'|'.$line.'|'.Scrubber::normalise(Scrubber::text($e->getMessage())));
    }

    private static function store(Throwable $e): void
    {
        [$file, $line] = self::location($e);
        $now = now();
        $context = self::requestContext();
        $fingerprint = self::fingerprint($e);

        $update = function (ErrorEvent $event) use ($now, $context) {
            $changes = array_merge($context, ['occurrences' => $event->occurrences + 1, 'last_seen_at' => $now]);
            if ($event->status === ErrorEvent::STATUS_RESOLVED) {
                $changes += ['status' => ErrorEvent::STATUS_OPEN, 'regressed_at' => $now, 'resolved_at' => null, 'resolved_by_admin_id' => null];
            }
            unset($changes['occurrences']);
            // Atomic counter: concurrent requests never lose an occurrence.
            ErrorEvent::query()->whereKey($event->id)->increment('occurrences', 1, $changes + ['updated_at' => $now]);
        };

        $existing = ErrorEvent::query()->where('fingerprint', $fingerprint)->first();
        if ($existing) {
            $update($existing);

            return;
        }

        try {
            ErrorEvent::query()->create(array_merge($context, [
                'fingerprint' => $fingerprint,
                'exception_class' => mb_substr(get_class($e), 0, 255),
                'message' => mb_substr(Scrubber::text($e->getMessage()), 0, 1000),
                'file' => mb_substr($file, 0, 500),
                'line' => $line,
                'trace' => self::trace($e),
                'status' => ErrorEvent::STATUS_OPEN,
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'environment' => mb_substr((string) app()->environment(), 0, 32),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another process stored the same error a moment ago.
            if ($existing = ErrorEvent::query()->where('fingerprint', $fingerprint)->first()) {
                $update($existing);
            }
        }
    }

    /** @return array<string, string|null> */
    private static function requestContext(): array
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $route = $request?->route();

        return [
            'last_request_id' => self::cut(Context::get('request_id'), 64),
            'last_method' => $request ? self::cut($request->method(), 10) : 'CLI',
            'last_path' => $request ? self::cut('/'.ltrim($request->path(), '/'), 500) : self::cut(implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 2)), 500),
            'last_route' => self::cut($route?->getName() ?? $route?->uri(), 255),
            'last_actor' => self::actor(),
        ];
    }

    private static function actor(): ?string
    {
        foreach (['admin_web' => 'admin', 'admin' => 'admin', 'user' => 'customer', 'driver' => 'driver'] as $guard => $type) {
            try {
                if (config("auth.guards.{$guard}") && ($user = Auth::guard($guard)->user())) {
                    return $type.':'.$user->getAuthIdentifier();
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /** file:line class->function, application frames first; never arguments. */
    private static function trace(Throwable $e): string
    {
        $lines = [];
        foreach (array_slice($e->getTrace(), 0, 40) as $i => $frame) {
            $where = isset($frame['file']) ? self::relative($frame['file']).':'.($frame['line'] ?? '?') : '[internal]';
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $lines[] = "#{$i} {$where} {$call}()";
        }
        if ($previous = $e->getPrevious()) {
            $lines[] = 'Caused by '.get_class($previous).': '.Scrubber::text(mb_substr($previous->getMessage(), 0, 300));
        }

        return mb_substr(Scrubber::text(implode("\n", $lines)), 0, 12000);
    }

    private static function relative(string $file): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
