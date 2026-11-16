{{-- WAFFRLI (2026-11-16, Phase 3) — map picker for admin forms
     (App\Filament\Support\MapPicker). Leaflet is self-hosted
     (public/vendor/leaflet); tiles come from config('waffrli.driver_map').

     mode "point":    a marker bound to the form's latitude/longitude fields.
                      Click the map or drag the marker to set them; typing in
                      the fields moves the marker. Optional radius field: a
                      circle shows the delivery radius (cities).
     mode "boundary": read-only preview of the GeoJSON polygon typed in the
                      boundary field, so a wrong boundary is seen before saving.

     The picker itself is not saved (dehydrated(false)); only the real fields are. --}}
@php
    $base = \Illuminate\Support\Str::beforeLast($getStatePath(), '.');
    $path = fn (?string $field) => $field ? $base.'.'.$field : null;
    $config = [
        'mode' => $mode,
        'lat' => $path($lat ?? null),
        'lng' => $path($lng ?? null),
        'radius' => $path($radius ?? null),
        'boundary' => $path($boundary ?? null),
        'center' => [(float) ($center[0] ?? 35.1318), (float) ($center[1] ?? 36.7578)],
        'tileUrl' => config('waffrli.driver_map.tile_url'),
        'tileAttribution' => config('waffrli.driver_map.tile_attribution'),
        'leafletCss' => asset('vendor/leaflet/leaflet.css'),
        'leafletJs' => asset('vendor/leaflet/leaflet.js'),
        'invalid' => __('admin.map_picker.invalid_boundary'),
    ];
@endphp
<div
    wire:ignore
    x-data="{
        cfg: @js($config),
        map: null, marker: null, circle: null, shape: null, message: '',
        num(value) { const n = parseFloat(value); return Number.isFinite(n) ? n : null; },
        get(path) { return path ? $wire.$get(path) : null; },
        init() {
            const boot = () => this.boot();
            if (window.L) { boot(); return; }
            if (! document.querySelector('link[data-leaflet]')) {
                const css = document.createElement('link');
                css.rel = 'stylesheet'; css.href = this.cfg.leafletCss; css.dataset.leaflet = '1';
                document.head.appendChild(css);
            }
            const existing = document.querySelector('script[data-leaflet]');
            if (existing) { existing.addEventListener('load', boot, { once: true }); return; }
            const js = document.createElement('script');
            js.src = this.cfg.leafletJs; js.dataset.leaflet = '1'; js.onload = boot;
            document.head.appendChild(js);
        },
        boot() {
            this.map = L.map(this.$refs.map, { scrollWheelZoom: false }).setView(this.cfg.center, 12);
            L.tileLayer(this.cfg.tileUrl, { maxZoom: 19, attribution: this.cfg.tileAttribution }).addTo(this.map);
            if (this.cfg.mode === 'point') {
                this.map.on('click', (e) => this.setPoint(e.latlng.lat, e.latlng.lng));
                this.drawPoint(true);
                [this.cfg.lat, this.cfg.lng, this.cfg.radius].filter(Boolean).forEach((p) => $wire.$watch(p, () => this.drawPoint(false)));
            } else {
                this.drawBoundary();
                $wire.$watch(this.cfg.boundary, () => this.drawBoundary());
            }
            setTimeout(() => this.map.invalidateSize(), 150);
        },
        setPoint(lat, lng) {
            $wire.$set(this.cfg.lat, Number(lat.toFixed(7)), false);
            $wire.$set(this.cfg.lng, Number(lng.toFixed(7)), false);
            this.drawPoint(false);
        },
        drawPoint(fit) {
            const lat = this.num(this.get(this.cfg.lat)), lng = this.num(this.get(this.cfg.lng));
            if (lat === null || lng === null) { return; }
            if (! this.marker) {
                this.marker = L.marker([lat, lng], { draggable: true }).addTo(this.map);
                this.marker.on('dragend', () => { const p = this.marker.getLatLng(); this.setPoint(p.lat, p.lng); });
            } else {
                this.marker.setLatLng([lat, lng]);
            }
            const km = this.num(this.get(this.cfg.radius));
            if (this.cfg.radius && km) {
                this.circle ? this.circle.setLatLng([lat, lng]).setRadius(km * 1000)
                    : (this.circle = L.circle([lat, lng], { radius: km * 1000, color: '#168a63', weight: 2, fillOpacity: 0.08 }).addTo(this.map));
            }
            if (fit) { this.circle ? this.map.fitBounds(this.circle.getBounds(), { padding: [12, 12] }) : this.map.setView([lat, lng], 15); }
        },
        drawBoundary() {
            this.message = '';
            if (this.shape) { this.shape.remove(); this.shape = null; }
            let geo = this.get(this.cfg.boundary);
            try { geo = typeof geo === 'string' ? JSON.parse(geo) : geo; } catch (e) { geo = null; }
            const ring = geo && geo.type === 'Polygon' && Array.isArray(geo.coordinates) ? geo.coordinates[0] : null;
            if (! ring || ring.length < 4) { if (this.get(this.cfg.boundary)) { this.message = this.cfg.invalid; } return; }
            this.shape = L.polygon(ring.map(([x, y]) => [y, x]), { color: '#168a63', weight: 2, fillOpacity: 0.12 }).addTo(this.map);
            this.map.fitBounds(this.shape.getBounds(), { padding: [12, 12] });
        },
    }"
    class="wf-map-picker"
>
    <div x-ref="map" class="wf-map-frame" style="height: 320px; border-radius: 0.75rem; overflow: hidden;"></div>
    <p x-show="message" x-text="message" class="mt-2 text-sm" style="color: var(--danger-600)"></p>
</div>
