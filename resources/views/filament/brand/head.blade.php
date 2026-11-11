{{-- WAFFRLI (2026-11-04): icons and browser chrome colour from the brand.
     The "no driver available" order-row highlight (dispatch B4) lives in the
     admin theme (.waffrli-row-no-driver). --}}
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/brand/favicon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('images/brand/apple-touch-icon.png') }}">
<meta name="theme-color" content="{{ \App\Filament\Support\Brand::INK }}">
