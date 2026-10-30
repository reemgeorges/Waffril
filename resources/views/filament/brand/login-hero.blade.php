{{-- WAFFRLI (2026-11-04): the official logo on the login page. --}}
<div class="wf-login-hero">
    <picture>
        <source srcset="{{ asset('images/brand/waffrli-logo-256.webp') }} 1x, {{ asset('images/brand/waffrli-logo-768.webp') }} 3x" type="image/webp">
        <img src="{{ asset('images/brand/waffrli-logo-256.png') }}" alt="{{ __('admin.brand.logo_alt') }}" width="152" height="152">
    </picture>
    <p class="wf-login-hero__tagline">{{ __('admin.brand.tagline') }}</p>
    <span class="wf-login-hero__rule" aria-hidden="true"></span>
</div>
