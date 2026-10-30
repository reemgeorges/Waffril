<?php

namespace App\Http\Controllers\Realtime;

use App\Http\Controllers\Controller;
use App\Http\Traits\GeneralTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * WAFFRLI (2026-11-03) — Reverb private-channel authorization. Each route's
 * middleware authenticates exactly one identity (customer token, driver
 * token or the Filament admin_web session); routes/channels.php then checks
 * ownership / permissions.
 * Success returns the raw Pusher-protocol body ({"auth": "key:signature"})
 * that Pusher/Echo clients expect; refusals use the API envelope with 403.
 */
class BroadcastAuthController extends Controller
{
    use GeneralTrait;

    public function __invoke(Request $request)
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:200', 'regex:/^private-[A-Za-z0-9_\-=@,.;]+$/'],
        ]);

        try {
            return response()->json(Broadcast::auth($request));
        } catch (AccessDeniedHttpException) {
            return $this->forbiddenResponse(__('messages.realtime.channel_forbidden'));
        }
    }
}
