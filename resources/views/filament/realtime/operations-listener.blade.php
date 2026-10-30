{{-- WAFFRLI (2026-11-03): one subscription per panel page to the shared Reverb
     server's `admin.orders` channel (only for admins with orders.view, and only
     when config/filament.php has an Echo connection). Events carry identifiers
     only; components re-read the database with the viewer's permissions.
     Without Reverb, widgets keep their normal polling. --}}
@php($admin = \App\Support\Auth\AdminPermissions::current())
@if ($admin && config('filament.broadcasting.echo') && \App\Support\Realtime\ChannelAccess::adminOrders($admin))
    <script data-navigate-once>
        (() => {
            const start = () => {
                if (! window.Echo || window.waffrliOperationsListener) { return; }
                window.waffrliOperationsListener = true;
                let timer = null;
                const changed = () => {
                    clearTimeout(timer);
                    timer = setTimeout(() => window.Livewire && window.Livewire.dispatch('waffrli-orders-changed'), 750);
                };
                window.Echo.private('admin.orders')
                    .listen('.order.status_changed', changed)
                    .listen('.admin.operational', changed);
            };
            window.Echo ? start() : window.addEventListener('EchoLoaded', start, { once: true });
        })();
    </script>
@endif
