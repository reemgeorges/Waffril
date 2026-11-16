{{-- WAFFRLI realtime listener for the admin panel (2026-11-03, extended 2026-11-16).

     One subscription set per browser tab to the shared Reverb server:
       - `admin.orders`   (admins with orders.view): order lifecycle + PLUS requests
       - `admin.activity` (any active admin): refunds, PLUS, customers, drivers,
                           settlements, marketing progress
     Events carry identifiers only. The listener just tells Livewire components
     "something changed"; every component re-reads the database with the
     viewer's own permissions and filters (nothing from the event is rendered).

     Livewire browser events dispatched (debounced, 750 ms):
       - waffrli-orders-changed      (order events; dashboards, order lists, ViewOrder)
       - waffrli-operations-changed  (every event; all other live list pages)

     Fallback: while the socket is unavailable/disconnected, both events are
     dispatched every 30 s (only while the tab is visible); after a reconnect
     one catch-up refresh is dispatched and polling stops. Without Echo
     configured nothing is loaded and widgets keep their own poll intervals. --}}
@php($admin = \App\Support\Auth\AdminPermissions::current())
@if ($admin && config('filament.broadcasting.echo') && \App\Support\Realtime\ChannelAccess::adminActivity($admin))
    @php($ordersAllowed = \App\Support\Realtime\ChannelAccess::adminOrders($admin))
    <script data-navigate-once>
        (() => {
            const ORDERS_ALLOWED = @json($ordersAllowed);
            const FALLBACK_MS = 30000;
            const DEBOUNCE_MS = 750;

            const start = () => {
                if (! window.Echo || window.waffrliOperationsListener) { return; }
                window.waffrliOperationsListener = true;

                let timer = null;
                let pendingOrders = false;
                const fire = (orders) => {
                    if (! window.Livewire) { return; }
                    if (orders) { window.Livewire.dispatch('waffrli-orders-changed'); }
                    window.Livewire.dispatch('waffrli-operations-changed');
                };
                const changed = (orders) => {
                    pendingOrders = pendingOrders || orders;
                    clearTimeout(timer);
                    timer = setTimeout(() => { fire(pendingOrders); pendingOrders = false; }, DEBOUNCE_MS);
                };

                if (ORDERS_ALLOWED) {
                    window.Echo.private('admin.orders')
                        .listen('.order.status_changed', () => changed(true))
                        .listen('.admin.operational', () => changed(true));
                }
                window.Echo.private('admin.activity')
                    .listen('.admin.operational', () => changed(false));

                // Fallback polling while the websocket is down.
                let poller = null;
                let wasDown = false;
                const stopPolling = () => { clearInterval(poller); poller = null; };
                const startPolling = () => {
                    if (poller) { return; }
                    poller = setInterval(() => {
                        if (document.visibilityState === 'visible') { fire(ORDERS_ALLOWED); }
                    }, FALLBACK_MS);
                };
                const connection = window.Echo.connector && window.Echo.connector.pusher
                    ? window.Echo.connector.pusher.connection : null;
                if (connection && connection.bind) {
                    connection.bind('state_change', ({ current }) => {
                        if (current === 'connected') {
                            stopPolling();
                            if (wasDown) { wasDown = false; fire(ORDERS_ALLOWED); }
                        } else if (['unavailable', 'disconnected', 'failed'].includes(current)) {
                            wasDown = true;
                            startPolling();
                        }
                    });
                }
            };
            window.Echo ? start() : window.addEventListener('EchoLoaded', start, { once: true });
        })();
    </script>
@endif
