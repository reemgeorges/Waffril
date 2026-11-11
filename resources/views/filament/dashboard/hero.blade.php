{{-- WAFFRLI dashboard hero (UX redesign round 2, 2026-11-11): the brand
     panel at the top of the dashboard. Data: App\Filament\Pages\Dashboard::getHeader(). --}}
<header class="wf-hero" data-testid="dashboard-hero">
    <img class="wf-hero__mark" src="{{ asset('images/brand/waffrli-logo-256.webp') }}" alt="" aria-hidden="true">
    <div class="wf-hero__main">
        <p class="wf-hero__eyebrow">{{ $greeting }}</p>
        <h1 class="wf-hero__title">{{ $title }}</h1>
        <p class="wf-hero__date">
            <x-filament::icon icon="heroicon-m-calendar-days" class="wf-hero__date-icon" />
            {{ $date }}
        </p>
        @if ($chips)
            <div class="wf-hero__chips">
                @foreach ($chips as $chip)
                    <a href="{{ $chip['url'] }}" class="wf-hero__chip" data-tone="{{ $chip['tone'] }}">
                        <span class="wf-hero__chip-dot" aria-hidden="true"></span>
                        <strong class="wf-num">{{ $chip['count'] }}</strong>
                        <span>{{ $chip['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
    @if ($shortcuts)
        <nav class="wf-hero__actions" aria-label="{{ __('admin.dashboard.shortcuts') }}">
            @foreach ($shortcuts as $shortcut)
                <a href="{{ $shortcut['url'] }}" @class(['wf-hero__btn', 'wf-hero__btn--primary' => $shortcut['primary'] ?? false])>
                    <x-filament::icon :icon="$shortcut['icon']" class="wf-hero__btn-icon" />
                    <span>{{ $shortcut['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif
</header>
