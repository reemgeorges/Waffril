<?php

namespace Tests\Feature\Realtime;

use Illuminate\Support\Env;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WAFFRLI (2026-11-05): Reverb origins. Browsers are restricted to the listed
 * HOST NAMES; REVERB_ALLOW_ORIGINLESS_CLIENTS admits clients without an Origin
 * header (native apps) — off unless explicitly enabled. Mirrors Reverb's own
 * check (Server::verifyOrigin: Str::is(pattern, host of Origin)).
 */
class ReverbOriginConfigTest extends TestCase
{
    /** @return list<string> */
    private function origins(array $env): array
    {
        $saved = [];
        foreach ($env as $key => $value) {
            $saved[$key] = getenv($key);
            $value === null ? putenv($key) : putenv("{$key}={$value}");
            $value === null ? Env::getRepository()->clear($key) : Env::getRepository()->set($key, $value);
        }
        try {
            return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
        } finally {
            foreach ($saved as $key => $value) {
                $value === false ? Env::getRepository()->clear($key) : Env::getRepository()->set($key, $value);
            }
        }
    }

    private function allows(array $allowed, ?string $originHeader): bool
    {
        if (in_array('*', $allowed, true)) {
            return true;
        }
        $host = parse_url((string) $originHeader, PHP_URL_HOST);

        return collect($allowed)->contains(fn ($pattern) => Str::is($pattern, $host));
    }

    public function test_origin_less_clients_are_refused_unless_explicitly_enabled(): void
    {
        $allowed = $this->origins(['REVERB_ALLOWED_ORIGINS' => 'admin.example.com', 'REVERB_ALLOW_ORIGINLESS_CLIENTS' => null]);
        $this->assertSame(['admin.example.com'], $allowed);
        $this->assertTrue($this->allows($allowed, 'https://admin.example.com'));
        $this->assertFalse($this->allows($allowed, null), 'no Origin header');
    }

    public function test_enabled_flag_admits_native_clients_but_never_foreign_sites(): void
    {
        $allowed = $this->origins(['REVERB_ALLOWED_ORIGINS' => 'admin.example.com', 'REVERB_ALLOW_ORIGINLESS_CLIENTS' => 'true']);
        $this->assertSame(['admin.example.com', ''], $allowed);
        $this->assertTrue($this->allows($allowed, null), 'native app without Origin');
        $this->assertTrue($this->allows($allowed, 'https://admin.example.com'));
        foreach (['https://evil.example', 'https://admin.example.com.evil.example', 'https://evil.example/admin.example.com'] as $foreign) {
            $this->assertFalse($this->allows($allowed, $foreign), $foreign);
        }
        $this->assertNotContains('*', $allowed, 'never a wildcard');
    }
}
