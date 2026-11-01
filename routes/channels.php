<?php

use App\Support\Realtime\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
| WAFFRLI (2026-11-03) — private channels of the ONE shared Reverb server.
| Names are declared without the `private-` prefix (clients subscribe to
| `private-order.12`, Echo to `Echo.private('order.12')`).
|
| Each auth endpoint authenticates exactly one identity, so the callbacks
| also check the model type:
|   POST /api/v1/customer/broadcasting/auth  (Sanctum `user` token)
|   POST /api/v1/driver/broadcasting/auth    (Sanctum `driver` token)
|   POST /broadcasting/auth                  (Filament `admin_web` session)
|
| The admin.orders / admin.drivers-map patterns MUST stay above admin.{id}:
| the first matching pattern decides.
*/

Broadcast::channel('user.{id}', fn ($user, $id) => ChannelAccess::user($user, $id));

Broadcast::channel('driver.{id}', fn ($driver, $id) => ChannelAccess::driver($driver, $id));

Broadcast::channel('order.{id}', fn ($actor, $id) => ChannelAccess::order($actor, $id));

Broadcast::channel('admin.orders', fn ($admin) => ChannelAccess::adminOrders($admin));

Broadcast::channel('admin.drivers-map', fn ($admin) => ChannelAccess::adminDriversMap($admin));

Broadcast::channel('admin.{id}', fn ($admin, $id) => ChannelAccess::admin($admin, $id));
