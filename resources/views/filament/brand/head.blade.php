{{-- WAFFRLI (2026-11-04): icons and browser chrome colour from the brand.
     The "no driver available" order-row highlight (dispatch B4) lives in the
     admin theme (.waffrli-row-no-driver). --}}
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/brand/favicon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('images/brand/apple-touch-icon.png') }}">
<meta name="theme-color" content="{{ \App\Filament\Support\Brand::INK }}">
{{-- UX redesign (2026-11-11): Readex Pro, the self-hosted display face for
     page titles, section headings and key figures (body text stays IBM Plex
     Sans Arabic, loaded by the panel's font provider). --}}
<link rel="preload" href="{{ asset('fonts/readex-pro/readex-pro-arabic-600-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('fonts/readex-pro/font.css') }}">
