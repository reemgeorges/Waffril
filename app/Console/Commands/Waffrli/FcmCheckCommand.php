<?php

namespace App\Console\Commands\Waffrli;

use App\Contracts\Push\PushTransport;
use App\Models\DeviceToken;
use App\Models\Driver;
use App\Models\User;
use App\Services\Push\FcmHttpV1Transport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * WAFFRLI (2026-11-16, Phase 11) — Firebase Cloud Messaging readiness.
 *
 * Read-only by default: it checks configuration, the service-account
 * credentials (parsed and the private key loaded LOCALLY), the queue, the
 * registered device tokens and recent push failures. It never prints a
 * private key, an access token or a device token, and never sends a
 * notification.
 *
 *   php artisan waffrli:fcm-check                  # local checks only, no network
 *   php artisan waffrli:fcm-check --online         # + obtain an OAuth access token from Google
 *   php artisan waffrli:fcm-check --validate-token=42
 *        # + FCM "validate_only" dry run for device token #42 (nothing is delivered)
 *   php artisan waffrli:fcm-check --report         # also write storage/app/diagnostics/fcm-readiness-*.json
 *
 * Exit code 1 when any check FAILS (warnings do not fail).
 */
class FcmCheckCommand extends Command
{
    protected $signature = 'waffrli:fcm-check
        {--online : Also request an OAuth access token from Google (network)}
        {--validate-token= : Device token id for an FCM validate_only dry run (network; nothing is delivered)}
        {--report : Write a JSON report (no secrets) under storage/app/diagnostics}';

    protected $description = 'Check Firebase Cloud Messaging (push) readiness without sending anything';

    /** @var list<array{check: string, status: string, detail: string}> */
    private array $results = [];

    public function handle(): int
    {
        $enabled = (bool) config('waffrli.push.enabled');
        $driver = (string) config('waffrli.push.driver');
        $production = app()->isProduction();

        $this->record('push.enabled', $enabled ? 'PASS' : ($production ? 'FAIL' : 'WARN'),
            $enabled ? 'Push is enabled.' : 'WAFFRLI_PUSH_ENABLED is false: no phone notifications are sent.');
        $this->record('push.driver', $driver === 'fcm' ? 'PASS' : ($production ? 'FAIL' : 'WARN'),
            $driver === 'fcm' ? 'FCM HTTP v1 transport.' : "Driver \"{$driver}\": messages are recorded, not delivered to phones (set WAFFRLI_PUSH_DRIVER=fcm).");

        $fcm = app(FcmHttpV1Transport::class);
        $credentialsOk = false;
        try {
            $summary = $fcm->credentialSummary();
            $credentialsOk = true;
            $this->record('credentials', 'PASS', sprintf('Project "%s", service account %s, from %s; private key loads.',
                $summary['project_id'], $this->maskEmail($summary['client_email']), $summary['source']));
            $path = (string) config('waffrli.push.fcm.credentials');
            if ($path !== '' && str_starts_with(realpath($path) ?: $path, base_path().DIRECTORY_SEPARATOR) && ! str_starts_with(realpath($path) ?: $path, storage_path())) {
                $this->record('credentials.location', 'WARN', 'The credentials file is inside the project directory; keep it outside the repository (or in storage/, which is git-ignored).');
            }
        } catch (Throwable $e) {
            $this->record('credentials', $driver === 'fcm' ? 'FAIL' : 'WARN', $this->safe($e->getMessage()));
        }

        $this->checkQueue($production);
        $this->checkTokens();
        $this->checkFailures();

        if ($this->option('online') || $this->option('validate-token')) {
            if (! $credentialsOk) {
                $this->record('oauth', 'FAIL', 'Skipped: credentials are not usable.');
            } else {
                try {
                    $fcm->canAuthenticate();
                    $this->record('oauth', 'PASS', 'Google issued an access token for firebase.messaging.');
                } catch (Throwable $e) {
                    $this->record('oauth', 'FAIL', $this->safe($e->getMessage()));
                }
            }
        }

        if ($id = $this->option('validate-token')) {
            $token = DeviceToken::query()->find((int) $id);
            if (! $token) {
                $this->record('validate_only', 'FAIL', "Device token #{$id} does not exist.");
            } elseif ($credentialsOk) {
                try {
                    $result = $fcm->validateOnly($token->token);
                    $this->record('validate_only', $result === PushTransport::RESULT_SENT ? 'PASS' : 'FAIL',
                        $result === PushTransport::RESULT_SENT ? "FCM accepts device token #{$id} ({$token->platform}); nothing was delivered."
                            : "FCM reports device token #{$id} as invalid or unregistered.");
                } catch (Throwable $e) {
                    $this->record('validate_only', 'FAIL', $this->safe($e->getMessage()));
                }
            }
        }

        $this->table(['Check', 'Status', 'Detail'], array_map(fn ($r) => [$r['check'], $r['status'], $r['detail']], $this->results));

        if ($this->option('report')) {
            $path = 'diagnostics/fcm-readiness-'.now()->format('Ymd-His').'.json';
            Storage::disk('local')->put($path, json_encode([
                'generated_at' => now()->toIso8601String(),
                'environment' => app()->environment(),
                'results' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line('Report: storage/app/'.$path);
        }

        $failed = collect($this->results)->where('status', 'FAIL')->count();
        $this->line($failed ? "{$failed} check(s) failed." : 'No failing checks.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkQueue(bool $production): void
    {
        $connection = (string) config('queue.default');
        if ($connection === 'sync') {
            $this->record('queue', $production ? 'FAIL' : 'WARN', 'QUEUE_CONNECTION=sync: pushes are sent inside the web request; use a worker (database/redis) in production.');

            return;
        }
        $detail = "Connection \"{$connection}\", push queue \"".config('waffrli.push.queue', 'default').'". A worker must be running (php artisan queue:work).';
        if ($connection === 'database' && Schema::hasTable('jobs')) {
            $waiting = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $age = $oldest ? now()->getTimestamp() - (int) $oldest : 0;
            $detail .= " Waiting jobs: {$waiting}".($oldest ? ', oldest '.round($age / 60).' min.' : '.');
            if ($age > 600) {
                $this->record('queue', 'WARN', $detail.' The oldest job waits over 10 minutes: is the worker running?');

                return;
            }
        }
        $this->record('queue', 'PASS', $detail);
    }

    private function checkTokens(): void
    {
        $active = DeviceToken::query()->where('is_active', true)->whereNull('revoked_at');
        $customers = (clone $active)->where('tokenable_type', (new User)->getMorphClass())->count();
        $drivers = (clone $active)->where('tokenable_type', (new Driver)->getMorphClass())->count();
        $stale = (clone $active)->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(60)))->count();
        $platforms = (clone $active)->selectRaw('platform, COUNT(*) AS n')->groupBy('platform')->pluck('n', 'platform')->map(fn ($n, $p) => "{$p}: {$n}")->implode(', ');

        $this->record('device_tokens', $customers + $drivers > 0 ? 'PASS' : 'WARN',
            "Active tokens: customers {$customers}, drivers {$drivers}".($platforms ? " ({$platforms})" : '').'.');
        if ($stale > 0) {
            $this->record('device_tokens.stale', 'WARN', "{$stale} active token(s) not seen for 60 days or never: they may be uninstalled apps (FCM will report them as unregistered on the next send).");
        }
    }

    private function checkFailures(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            return;
        }
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())
            ->where(fn ($q) => $q->where('payload', 'like', '%SendPushMessage%')->orWhere('payload', 'like', '%SendMarketingBroadcast%'))
            ->count();
        $this->record('failed_push_jobs_24h', $failed === 0 ? 'PASS' : 'WARN',
            $failed === 0 ? 'No failed push jobs in the last 24 hours.' : "{$failed} failed push job(s) in the last 24 hours (see failed_jobs / php artisan queue:failed).");
    }

    private function record(string $check, string $status, string $detail): void
    {
        $this->results[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 3).'***@'.$domain;
    }

    /** Error text without anything that looks like a key or token. */
    private function safe(string $message): string
    {
        $message = preg_replace('/-----BEGIN[^-]+-----.*?-----END[^-]+-----/s', '[redacted key]', $message) ?? $message;

        return preg_replace('/\b(ya29\.|eyJ)[A-Za-z0-9._\-]+/', '[redacted token]', $message) ?? $message;
    }
}
