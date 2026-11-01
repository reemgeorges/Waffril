{{-- WAFFRLI (2026-11-04): header/sidebar lockup — the official logo artwork
     (unaltered, background corners masked) + the bilingual brand name as text
     for readability at small sizes. --}}
<span class="wf-brand">
    <picture>
        <source srcset="{{ asset('images/brand/waffrli-logo-96.webp') }}" type="image/webp">
        <img src="{{ asset('images/brand/waffrli-logo-96.png') }}" alt="{{ __('admin.brand.logo_alt') }}" class="wf-brand__mark" width="44" height="44">
    </picture>
    <span class="wf-brand__text">
        <span class="wf-brand__en" lang="en" dir="ltr">WAFFRLI</span>
        <span class="wf-brand__ar" lang="ar" dir="rtl">وفرلي</span>
    </span>
</span>
