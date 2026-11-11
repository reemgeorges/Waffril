{{-- WAFFRLI dashboard, "Today" band (UX redesign 2026-11-11).
     Data: App\Filament\Widgets\TodayOverview::figures(). --}}
<x-filament-widgets::widget>
    <section class="wf-today" data-testid="today-overview" aria-labelledby="wf-today-title">
        <h2 id="wf-today-title" class="wf-today__title">
            <x-filament::icon icon="heroicon-o-calendar-days" class="wf-today__title-icon" />
            {{ __('admin.dashboard.today') }}
        </h2>
        <dl class="wf-today__metrics">
            <div class="wf-metric">
                <dt>{{ __('admin.dashboard.received_today') }}</dt>
                <dd class="wf-num">{{ $f['received'] }}</dd>
            </div>
            <div class="wf-metric" data-tone="success">
                <dt>{{ __('admin.dashboard.delivered_today') }}</dt>
                <dd class="wf-num">{{ $f['delivered'] }}</dd>
            </div>
            <div class="wf-metric" data-tone="danger">
                <dt>{{ __('admin.dashboard.cancelled_today') }}</dt>
                <dd class="wf-num">{{ $f['cancelled'] }}</dd>
            </div>
            <div class="wf-metric">
                <dt>{{ __('admin.dashboard.avg_delivery_time') }}</dt>
                <dd>
                    @if ($f['avg_minutes'] !== null)
                        <span class="wf-num">{{ $f['avg_minutes'] }}</span>
                        <span class="wf-metric__unit">{{ __('admin.dashboard.minutes') }}</span>
                    @else
                        <span class="wf-metric__empty">—</span>
                    @endif
                </dd>
            </div>
            <div class="wf-metric wf-metric--wide">
                <dt>{{ __('admin.dashboard.delivered_value_today') }}</dt>
                <dd>
                    @forelse ($f['sales'] as $sale)
                        <span class="wf-money wf-ltr">{{ $sale['amount'] }}</span>
                    @empty
                        <span class="wf-metric__empty">{{ __('admin.dashboard.no_deliveries_yet') }}</span>
                    @endforelse
                </dd>
            </div>
        </dl>
    </section>
</x-filament-widgets::widget>
