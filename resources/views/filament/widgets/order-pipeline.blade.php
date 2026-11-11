{{-- WAFFRLI dashboard, "Order pipeline" (UX redesign 2026-11-11).
     Data: App\Filament\Widgets\OrdersByStatusChart. Bar length = count relative
     to the busiest open stage; the number is always written beside it. --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('admin.dashboard.pipeline_heading')" :description="trans_choice('admin.dashboard.pipeline_open', $open, ['count' => $open])">
        <ol class="wf-pipeline" data-testid="order-pipeline">
            @foreach ($stages as $stage)
                <li>
                    <a href="{{ $stage['url'] }}" class="wf-pipeline__row" data-status="{{ $stage['status'] }}">
                        <span class="wf-pipeline__label">{{ $stage['label'] }}</span>
                        <span class="wf-pipeline__track" aria-hidden="true">
                            <span class="wf-pipeline__bar" style="inline-size: {{ $stage['count'] > 0 ? max(4, $stage['share']) : 0 }}%"></span>
                        </span>
                        <span class="wf-pipeline__count wf-num">{{ $stage['count'] }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
        <div class="wf-pipeline__footer">
            <span><span class="wf-dot" data-tone="success" aria-hidden="true"></span>{{ __('admin.order_status.delivered') }} <strong class="wf-num">{{ $delivered }}</strong></span>
            <span><span class="wf-dot" data-tone="danger" aria-hidden="true"></span>{{ __('admin.order_status.cancelled') }} <strong class="wf-num">{{ $cancelled }}</strong></span>
            <a href="{{ $allUrl }}" class="wf-link">{{ __('admin.dashboard.all_orders') }}</a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
