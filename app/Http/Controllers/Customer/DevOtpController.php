<?php

namespace App\Http\Controllers\Customer;

use App\Services\Messaging\FakeTextGateway;
use App\Support\Phone\PhoneNumber;
use Illuminate\Http\Request;

/**
 * WAFFRLI (2026-11-03) — DEVELOPMENT ONLY: returns the last OTP text the
 * FAKE transport "sent" to a phone, so Bruno can complete the OTP flow
 * locally. The route is registered only when ALL hold (routes/api/v1/dev.php):
 * environment local/testing, messaging driver `fake`, and
 * WAFFRLI_DEV_OTP_PEEK_TOKEN set. Each call must send that token in
 * `X-Dev-Token`. It never verifies or bypasses anything.
 */
class DevOtpController extends CustomerController
{
    public function peek(Request $request)
    {
        $expected = (string) config('waffrli.dev.otp_peek_token');
        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Dev-Token'))) {
            return $this->forbiddenResponse();
        }

        $phone = PhoneNumber::normalize((string) $request->query('phone'));
        $last = $phone ? FakeTextGateway::last($phone) : null;
        if (! $last) {
            return $this->notFoundResponse(__('messages.customer.not_found'));
        }
        preg_match('/\b(\d{4,8})\b/', $last['message'], $m);

        return $this->apiResponse(['phone' => $phone, 'channel' => $last['channel'], 'code' => $m[1] ?? null, 'sent_at' => $last['at']]);
    }
}
