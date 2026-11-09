<?php

/*
 * WAFFRLI dispatch B2 (2026-11-10) — one competitor in a REAL concurrency
 * test (tests/Feature/Delivery/DeliveryOfferConcurrencyTest.php). Started
 * as a separate PHP process against the isolated MySQL test database;
 * waits until a shared start instant, performs one action on a delivery
 * offer and prints a JSON result line. Test-only: never used by the app.
 *
 *   php offer_race_worker.php <accept|expire|withdraw> <offerId> <driverId> <startAtMicrotime>
 */

use App\Exceptions\BusinessRuleException;
use App\Models\DeliveryOffer;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Delivery\DeliveryOfferService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'waffrli_test') {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'refusing: not the isolated waffrli_test database']).PHP_EOL);
    exit(2);
}
config(['dispatch.offers_enabled' => true]);

[, $action, $offerId, $driverId, $startAt] = $argv;
$service = app(DeliveryOfferService::class);
$offer = DeliveryOffer::findOrFail((int) $offerId);
$driver = Driver::findOrFail((int) $driverId);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}
$started = microtime(true);

try {
    $result = match ($action) {
        'accept' => $service->accept($offer, $driver)->status->value,
        'expire' => $service->expire($offer) ? 'expired' : 'noop',
        'withdraw' => ($service->cancelPendingForOrder(Order::findOrFail($offer->order_id), DeliveryOffer::CANCEL_ADMIN_OVERRIDE)?->status->value) ?? 'noop',
    };
    fwrite(STDOUT, json_encode(['ok' => true, 'action' => $action, 'result' => $result, 'started' => $started, 'finished' => microtime(true)]).PHP_EOL);
} catch (BusinessRuleException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'action' => $action, 'status' => $e->getStatus(), 'started' => $started, 'finished' => microtime(true)]).PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'action' => $action, 'error' => get_class($e).': '.$e->getMessage()]).PHP_EOL);
}
