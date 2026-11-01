{{-- WAFFRLI (2026-11-04): visible AR | EN switch (header and login page).
     POST + CSRF to admin.locale; the choice is saved on the signed-in admin. --}}
@php($current = app()->getLocale())
<div @class(['wf-locale-switch', 'wf-locale-switch--floating' => $floating ?? false]) role="group" aria-label="{{ __('admin.locale_switch.label') }}" data-testid="locale-switcher">
    @foreach (['ar' => ['عربي', 'العربية'], 'en' => ['EN', 'English']] as $code => [$short, $name])
        <form method="POST" action="{{ route('admin.locale', $code) }}">
            @csrf
            <button type="submit" lang="{{ $code }}" title="{{ $name }}" aria-label="{{ $name }}"
                @class(['wf-locale-switch__option', 'is-active' => $current === $code])
                aria-pressed="{{ $current === $code ? 'true' : 'false' }}"
                data-locale="{{ $code }}">{{ $short }}</button>
        </form>
    @endforeach
</div>
