<?php

namespace App\Services\Push;

use App\Contracts\Push\PushTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WAFFRLI (2026-11-03) — Firebase Cloud Messaging HTTP v1.
 *
 * Authentication: an OAuth2 access token obtained with a service-account
 * JWT (RS256, scope firebase.messaging), cached until shortly before it
 * expires. Credentials come only from config('waffrli.push.fcm') (env):
 * a JSON file path outside the repository, or project id / client email /
 * private key variables. Nothing here logs a token or a key.
 *
 * UNREGISTERED / INVALID_ARGUMENT-for-token answers return
 * RESULT_INVALID_TOKEN; 429 / 5xx / network errors throw (the job retries).
 */
class FcmHttpV1Transport implements PushTransport
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function send(string $token, array $message): string
    {
        $credentials = $this->credentials();
        $response = Http::timeout((int) config('waffrli.push.fcm.timeout', 10))
            ->withToken($this->accessToken($credentials))
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $message['title'], 'body' => $message['body']],
                    'data' => array_map('strval', $message['data']),
                    'android' => ['priority' => 'HIGH'],
                    'apns' => ['headers' => ['apns-priority' => '10']],
                ],
            ]);

        if ($response->successful()) {
            return self::RESULT_SENT;
        }

        $status = $response->status();
        $code = (string) data_get($response->json(), 'error.details.0.errorCode', data_get($response->json(), 'error.status', ''));
        if ($status === 404 || $code === 'UNREGISTERED' || ($status === 400 && in_array($code, ['INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'], true))) {
            return self::RESULT_INVALID_TOKEN;
        }
        if ($status === 401) {
            Cache::forget($this->cacheKey($credentials));
        }

        throw new RuntimeException("FCM send failed with HTTP {$status} {$code}.");
    }

    public function name(): string
    {
        return 'fcm';
    }

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri: string} */
    protected function credentials(): array
    {
        $config = config('waffrli.push.fcm');
        $json = [];
        if (! empty($config['credentials'])) {
            if (! is_readable($config['credentials'])) {
                throw new RuntimeException('FIREBASE_CREDENTIALS does not point to a readable file.');
            }
            $json = json_decode((string) file_get_contents($config['credentials']), true) ?: [];
        }

        $credentials = [
            'project_id' => (string) ($config['project_id'] ?: ($json['project_id'] ?? '')),
            'client_email' => (string) ($config['client_email'] ?: ($json['client_email'] ?? '')),
            'private_key' => str_replace('\\n', "\n", (string) ($config['private_key'] ?: ($json['private_key'] ?? ''))),
            'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
        if ($credentials['project_id'] === '' || $credentials['client_email'] === '' || $credentials['private_key'] === '') {
            throw new RuntimeException('FCM credentials are not configured (see config/waffrli.php push.fcm).');
        }

        return $credentials;
    }

    protected function accessToken(array $credentials): string
    {
        return Cache::remember($this->cacheKey($credentials), now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64Url(json_encode([
                    'iss' => $credentials['client_email'], 'scope' => self::SCOPE, 'aud' => $credentials['token_uri'],
                    'iat' => $now, 'exp' => $now + 3600,
                ])),
            ];
            $key = openssl_pkey_get_private($credentials['private_key']);
            if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The FCM private key could not be used to sign.');
            }
            $segments[] = $this->base64Url($signature);

            $response = Http::asForm()->timeout((int) config('waffrli.push.fcm.timeout', 10))->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);
            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token)) {
                throw new RuntimeException("FCM OAuth token request failed with HTTP {$response->status()}.");
            }

            return $token;
        });
    }

    protected function cacheKey(array $credentials): string
    {
        return 'waffrli:fcm:access-token:'.hash('sha256', $credentials['client_email']);
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
