{{-- WAFFRLI (2026-11-01): drivers with an active delivery only, at their last
     STORED position. 2026-11-03: live refresh over Reverb when configured. Leaflet is served from public/vendor/leaflet; tiles and
     attribution come from config/waffrli.php. Popups are built with DOM nodes
     (textContent), never from HTML strings, so stored names cannot inject markup. --}}
@php
    $cityCenters = \App\Models\City::query()->whereIn('id', array_keys($cities))->get()
        ->mapWithKeys(fn ($c) => [$c->id => [(float) $c->center_lat, (float) $c->center_lng]])->all();
@endphp
<x-filament-panels::page>
    <div wire:poll.{{ $refreshSeconds }}s="refreshMap" style="display:flex;flex-direction:column;gap:1rem">
        <x-filament::section>
            <p style="font-size:.875rem;opacity:.8;margin:0">{{ __('admin.driver_map.notice', ['seconds' => $refreshSeconds]) }}</p>
            <p style="font-size:.75rem;margin:.35rem 0 0" data-testid="driver-map-mode">
                @if ($realtime)
                    <x-filament::badge color="success" style="display:inline-flex">{{ __('admin.driver_map.live') }}</x-filament::badge>
                @else
                    <x-filament::badge color="gray" style="display:inline-flex">{{ __('admin.driver_map.polling', ['seconds' => $refreshSeconds]) }}</x-filament::badge>
                @endif
            </p>

            <div style="margin-top:1rem;display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));align-items:end">
                <label style="display:block;font-size:.875rem">
                    <span style="font-weight:500;display:block;margin-bottom:.25rem">{{ __('admin.driver_map.filter_city') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="cityId" data-testid="driver-map-city">
                            <option value="">{{ __('admin.driver_map.all_cities') }}</option>
                            @foreach ($cities as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label style="display:block;font-size:.875rem">
                    <span style="font-weight:500;display:block;margin-bottom:.25rem">{{ __('admin.driver_map.filter_status') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="status" data-testid="driver-map-status">
                            <option value="">{{ __('admin.driver_map.all_statuses') }}</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:1rem;font-size:.75rem;padding-bottom:.5rem">
                    <span style="display:inline-flex;align-items:center;gap:.35rem"><span style="display:inline-block;width:12px;height:12px;border-radius:9999px;background:#D9AD59"></span>{{ __('admin.driver_map.legend_assigned') }}</span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem"><span style="display:inline-block;width:12px;height:12px;border-radius:9999px;background:#20A053"></span>{{ __('admin.driver_map.legend_picked_up') }}</span>
                    <span style="display:inline-flex;align-items:center;gap:.35rem"><span style="display:inline-block;width:12px;height:12px;border-radius:9999px;background:#9ca3af;outline:2px dashed #4b5563"></span>{{ __('admin.driver_map.legend_stale') }}</span>
                </div>
            </div>
        </x-filament::section>

        <div
            wire:ignore
            data-testid="driver-map"
            x-data="{
                map: null,
                layer: null,
                labels: @js($labels),
                centers: @js($cityCenters),
                realtime: @js($realtime),
                pending: null,
                // Live updates: any GPS report or delivery status change on the
                // shared Reverb server triggers ONE server re-read (refreshMap),
                // which re-checks drivers.track and drops finished deliveries.
                // The wire:poll above stays as the fallback.
                listen() {
                    const subscribe = () => {
                        if (! window.Echo || this.subscribed) { return; }
                        this.subscribed = true;
                        const queue = () => {
                            if (this.pending) { return; }
                            this.pending = setTimeout(() => { this.pending = null; $wire.refreshMap(); }, 2000);
                        };
                        window.Echo.private('admin.drivers-map').listen('.driver.location', queue);
                        window.Echo.private('admin.orders').listen('.order.status_changed', queue);
                    };
                    window.Echo ? subscribe() : window.addEventListener('EchoLoaded', subscribe, { once: true });
                },
                init() {
                    if (this.realtime) { this.listen(); }
                    const boot = () => {
                        this.map = L.map(this.$refs.map, { scrollWheelZoom: true }).setView([33.5138, 36.2765], 11);
                        L.tileLayer(@js($tileUrl), { maxZoom: 19, attribution: @js($tileAttribution) }).addTo(this.map);
                        this.layer = L.featureGroup().addTo(this.map);
                        this.update(@js($markers), true);
                        window.waffrliDriverMap = this;
                    };
                    if (window.L) { boot(); return; }
                    if (! document.querySelector('link[data-leaflet]')) {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = @js(asset('vendor/leaflet/leaflet.css'));
                        css.dataset.leaflet = '1';
                        document.head.appendChild(css);
                    }
                    const js = document.createElement('script');
                    js.src = @js(asset('vendor/leaflet/leaflet.js'));
                    js.onload = boot;
                    document.head.appendChild(js);
                },
                node(tag, text, style) {
                    const el = document.createElement(tag);
                    if (text !== undefined && text !== null) { el.textContent = text; }
                    if (style) { el.style.cssText = style; }
                    return el;
                },
                popup(d) {
                    const box = this.node('div', null, 'min-width:190px');
                    box.appendChild(this.node('strong', d.name));
                    if (d.city) { box.appendChild(this.node('div', d.city, 'color:#6b7280;font-size:12px')); }
                    d.orders.forEach(o => {
                        const row = this.node('div', null, 'margin-top:6px');
                        row.appendChild(this.node('div', this.labels.order + ': ' + o.number));
                        row.appendChild(this.node('div', this.labels.status + ': ' + o.status_label));
                        if (o.branch) { row.appendChild(this.node('div', o.branch, 'color:#6b7280;font-size:12px')); }
                        box.appendChild(row);
                    });
                    box.appendChild(this.node('div', this.labels.updated + ': ' + (d.updated_human ?? this.labels.no_position), 'margin-top:6px;font-size:12px'));
                    if (d.stale) { box.appendChild(this.node('div', '⚠ ' + this.labels.stale, 'color:#b91c1c;font-weight:600;font-size:12px')); }
                    return box;
                },
                update(markers, fit) {
                    if (! this.map) { return; }
                    this.layer.clearLayers();
                    const located = markers.filter(d => d.lat !== null && d.lng !== null);
                    located.forEach(d => {
                        const colour = d.stale ? '#9ca3af' : (d.status === 'picked_up' ? '#20A053' : '#D9AD59');
                        L.circleMarker([d.lat, d.lng], {
                            radius: 11, weight: 3, color: d.stale ? '#4b5563' : '#ffffff', dashArray: d.stale ? '4 3' : null,
                            fillColor: colour, fillOpacity: 0.95,
                        })
                            .bindPopup(this.popup(d))
                            .bindTooltip(this.node('span', d.name + (d.stale ? ' (' + this.labels.stale + ')' : '')))
                            .addTo(this.layer);
                    });
                    this.$refs.count.textContent = located.length;
                    if (fit) {
                        if (located.length) {
                            this.map.fitBounds(this.layer.getBounds(), { maxZoom: 15, padding: [40, 40] });
                        } else {
                            const city = $wire.cityId && this.centers[$wire.cityId];
                            this.map.setView(city ?? [33.5138, 36.2765], 11);
                        }
                    }
                }
            }"
            x-on:driver-map-updated.window="update($event.detail.markers, $event.detail.fit)"
        >
            <div style="margin-bottom:.5rem;font-size:.875rem">
                {{ __('admin.driver_map.visible') }}: <span x-ref="count" data-testid="driver-map-count">{{ collect($markers)->whereNotNull('lat')->count() }}</span>
            </div>
            <div x-ref="map" class="wf-map-frame" style="height: 520px; z-index: 0;"></div>
        </div>

        <x-filament::section :heading="__('admin.drivers.active_deliveries')">
            @if (count($markers) === 0)
                <p style="font-size:.875rem;opacity:.7;margin:0">{{ __('admin.driver_map.none') }}</p>
            @else
                <table style="width:100%;font-size:.875rem;border-collapse:collapse" data-testid="driver-map-table">
                    <thead>
                        <tr>
                            <th style="text-align:start;padding:.5rem">{{ __('admin.fields.driver') }}</th>
                            <th style="text-align:start;padding:.5rem">{{ __('admin.fields.city') }}</th>
                            <th style="text-align:start;padding:.5rem">{{ __('admin.driver_map.updated') }}</th>
                            <th style="text-align:start;padding:.5rem">{{ __('admin.resources.order.plural') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($markers as $driver)
                            <tr style="border-top:1px solid rgba(127,127,127,.25)">
                                <td style="padding:.5rem;vertical-align:top">{{ $driver['name'] }} — {{ $driver['phone'] }}</td>
                                <td style="padding:.5rem;vertical-align:top">{{ $driver['city'] }}</td>
                                <td style="padding:.5rem;vertical-align:top">
                                    {{ $driver['updated_human'] ?? __('admin.driver_map.no_position') }}
                                    @if ($driver['stale'])
                                        <x-filament::badge color="danger" style="display:inline-flex;margin-inline-start:.35rem" :tooltip="__('admin.driver_map.stale_hint', ['minutes' => $staleMinutes])">{{ __('admin.driver_map.stale') }}</x-filament::badge>
                                    @endif
                                </td>
                                <td style="padding:.5rem;vertical-align:top">
                                    @foreach ($driver['orders'] as $order)
                                        <div>{{ $order['number'] }} · {{ $order['status_label'] }} · {{ $order['branch'] }}</div>
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
