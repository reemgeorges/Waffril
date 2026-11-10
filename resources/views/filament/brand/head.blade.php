{{-- WAFFRLI (2026-11-04): icons and browser chrome colour from the brand. --}}
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/brand/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/brand/favicon-192.png') }}">
<link rel="apple-touch-icon" href="{{ asset('images/brand/apple-touch-icon.png') }}">
<meta name="theme-color" content="{{ \App\Filament\Support\Brand::FOREST }}">
{{-- WAFFRLI dispatch B4 (2026-11-10): orders waiting with no available driver stand out in the order list. --}}
<style>
    .waffrli-row-no-driver { background-color: rgba(220, 38, 38, .07); box-shadow: inset 3px 0 0 rgba(220, 38, 38, .75); }
    [dir="rtl"] .waffrli-row-no-driver { box-shadow: inset -3px 0 0 rgba(220, 38, 38, .75); }
</style>
