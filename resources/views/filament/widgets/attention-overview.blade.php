{{-- WAFFRLI dashboard, "Needs your attention" (UX redesign 2026-11-11).
     Data: App\Filament\Widgets\OperationsOverview::items(). Styled by the admin
     theme (.wf-attention, .wf-task). A tile with work carries its tone, an
     icon and the words "Needs action"; colour is never the only signal. --}}
<x-filament-widgets::widget>
    <section class="wf-attention" data-testid="attention-overview" aria-labelledby="wf-attention-title">
        <header class="wf-attention__head">
            <div>
                <h2 id="wf-attention-title" class="wf-h2">{{ __('admin.dashboard.attention_title') }}</h2>
                <p class="wf-attention__summary">
                    @if ($open > 0)
                        {{ trans_choice('admin.dashboard.attention_summary', $open, ['count' => $open]) }}
                    @else
                        {{ __('admin.dashboard.attention_clear') }}
                    @endif
                    @if ($active !== null)
                        <span aria-hidden="true">·</span>
                        <a href="{{ $activeUrl }}" class="wf-link" data-testid="active-deliveries"><span class="wf-num">{{ $active }}</span> {{ __('admin.dashboard.active_deliveries') }}</a>
                    @endif
                </p>
            </div>

        </header>

        <div class="wf-attention__grid">
            @foreach ($items as $item)
                @php($hasWork = $item['count'] > 0)
                @php($tag = $item['url'] ? 'a' : 'div')
                <{{ $tag }} @if ($item['url']) href="{{ $item['url'] }}" @endif
                    class="wf-task" data-tone="{{ $item['tone'] }}" data-state="{{ $hasWork ? 'open' : 'clear' }}"
                    data-testid="attention-{{ $item['key'] }}">
                    <span class="wf-task__icon" aria-hidden="true">
                        <x-filament::icon :icon="$item['icon']" class="wf-task__svg" />
                    </span>
                    <span class="wf-task__body">
                        <span class="wf-task__label">{{ $item['label'] }}</span>
                        <span class="wf-task__hint">
                            @if ($hasWork)
                                {{ $item['hint'] }}
                            @else
                                {{ __('admin.dashboard.nothing_waiting') }}
                            @endif
                        </span>
                    </span>
                    <span class="wf-task__side">
                        <span class="wf-task__count wf-num">{{ $item['count'] }}</span>
                        <span class="wf-task__status">
                            @if ($hasWork)
                                <x-filament::icon icon="heroicon-m-arrow-small-left" class="wf-task__status-icon wf-flip-ltr" />
                                {{ __('admin.dashboard.needs_action') }}
                            @else
                                <x-filament::icon icon="heroicon-m-check" class="wf-task__status-icon" />
                                {{ __('admin.dashboard.all_clear') }}
                            @endif
                        </span>
                    </span>
                </{{ $tag }}>
            @endforeach
        </div>
    </section>
</x-filament-widgets::widget>
