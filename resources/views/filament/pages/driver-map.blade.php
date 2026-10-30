{{-- WAFFRLI (2026-10-31): drivers with active deliveries only. Polls the
     database every 30 s; live socket updates arrive when Reverb is installed. --}}
<x-filament-panels::page>
    <div wire:poll.30s="refreshMap" class="space-y-4">
        <x-filament::section>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('admin.drivers.map_notice') }}</p>
        </x-filament::section>

        <div
            wire:ignore
            x-data="{
                map: null,
                layer: null,
                init() {
                    const boot = () => {
                        this.map = L.map(this.$refs.map).setView([33.5138, 36.2765], 11);
                        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            maxZoom: 19, attribution: '&copy; OpenStreetMap'
                        }).addTo(this.map);
                        this.layer = L.layerGroup().addTo(this.map);
                        this.draw(@js($drivers));
                    };
                    if (window.L) { boot(); return; }
                    const css = document.createElement('link');
                    css.rel = 'stylesheet';
                    css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(css);
                    const js = document.createElement('script');
                    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    js.onload = boot;
                    document.head.appendChild(js);
                },
                draw(drivers) {
                    this.layer.clearLayers();
                    const points = [];
                    drivers.filter(d => d.lat !== null && d.lng !== null).forEach(d => {
                        const orders = d.orders.map(o => `${o.number} (${o.status})`).join('<br>');
                        L.marker([d.lat, d.lng]).bindPopup(`<b>${d.name}</b><br>${d.phone}<br>${orders}`).addTo(this.layer);
                        points.push([d.lat, d.lng]);
                    });
                    if (points.length) { this.map.fitBounds(points, { maxZoom: 14, padding: [30, 30] }); }
                }
            }"
            x-on:driver-map-refreshed.window="draw($event.detail.drivers)"
        >
            <div x-ref="map" style="height: 480px; border-radius: 0.75rem;"></div>
        </div>

        <x-filament::section :heading="__('admin.drivers.active_deliveries')">
            @if (count($drivers) === 0)
                <p class="text-sm text-gray-500">{{ __('admin.drivers.no_active_deliveries') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start">
                            <th class="text-start p-2">{{ __('admin.fields.driver') }}</th>
                            <th class="text-start p-2">{{ __('admin.fields.city') }}</th>
                            <th class="text-start p-2">{{ __('admin.fields.last_location_at') }}</th>
                            <th class="text-start p-2">{{ __('admin.resources.order.plural') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($drivers as $driver)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="p-2">{{ $driver['name'] }} — {{ $driver['phone'] }}</td>
                                <td class="p-2">{{ $driver['city'] }}</td>
                                <td class="p-2">{{ $driver['last_location_at'] ?? __('admin.drivers.no_position') }}</td>
                                <td class="p-2">
                                    @foreach ($driver['orders'] as $order)
                                        <div>{{ $order['number'] }} · {{ $order['status'] }} · {{ $order['branch'] }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
