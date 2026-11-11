{{-- WAFFRLI dashboard, today's KPI cards (UX redesign round 2, 2026-11-11).
     Data: App\Filament\Widgets\TodayOverview. Each card: icon chip, today's
     figure, a 14-day sparkline of the same measure and the change since
     yesterday in words (never colour alone). --}}
<x-filament-widgets::widget>
    <section class="wf-kpis" data-testid="today-overview" aria-labelledby="wf-today-title">
        <header class="wf-kpis__head">
            <h2 id="wf-today-title" class="wf-h2">{{ __('admin.dashboard.today') }}</h2>
            <span class="wf-kpis__sub">{{ __('admin.dashboard.kpi_hint') }}</span>
        </header>
        <div class="wf-kpis__grid">
            @foreach ($cards as $card)
                <article class="wf-kpi" data-tone="{{ $card['tone'] }}" data-testid="kpi-{{ $card['key'] }}">
                    <div class="wf-kpi__top">
                        <span class="wf-kpi__icon" aria-hidden="true"><x-filament::icon :icon="$card['icon']" class="wf-kpi__svg" /></span>
                        <span class="wf-kpi__label">{{ $card['label'] }}</span>
                    </div>
                    <div class="wf-kpi__value">
                        @if ($card['value'] !== null)
                            <span class="wf-num">{{ $card['value'] }}</span>
                            @isset($card['unit'])<span class="wf-kpi__unit">{{ $card['unit'] }}</span>@endisset
                        @else
                            <span class="wf-kpi__none">—</span>
                        @endif
                    </div>
                    <div class="wf-kpi__foot">
                        @if ($card['delta'] !== null && $card['delta'] !== 0)
                            @php($good = ($card['delta'] > 0) === $card['up_is_good'])
                            <span class="wf-kpi__delta" data-good="{{ $good ? 'yes' : 'no' }}">
                                <x-filament::icon :icon="$card['delta'] > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'" class="wf-kpi__delta-icon" />
                                {{ __('admin.dashboard.vs_yesterday', ['delta' => ($card['delta'] > 0 ? '+' : '−').abs($card['delta'])]) }}
                            </span>
                        @else
                            <span class="wf-kpi__delta" data-good="flat">{{ __('admin.dashboard.same_as_yesterday') }}</span>
                        @endif
                        @if ($card['has_data'])
                            <svg class="wf-kpi__spark" viewBox="0 0 100 32" preserveAspectRatio="none" role="img" aria-label="{{ __('admin.dashboard.spark_label', ['days' => 14]) }}">
                                <polygon points="{{ $card['spark']['area'] }}" class="wf-kpi__spark-area" />
                                <polyline points="{{ $card['spark']['line'] }}" class="wf-kpi__spark-line" vector-effect="non-scaling-stroke" />
                            </svg>
                        @endif
                    </div>
                </article>
            @endforeach

            <article class="wf-kpi wf-kpi--money" data-tone="gold" data-testid="kpi-value">
                <div class="wf-kpi__top">
                    <span class="wf-kpi__icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-banknotes" class="wf-kpi__svg" /></span>
                    <span class="wf-kpi__label">{{ __('admin.dashboard.delivered_value_today') }}</span>
                </div>
                <div class="wf-kpi__money">
                    @forelse ($f['sales'] as $sale)
                        <span class="wf-money wf-ltr">{{ $sale['amount'] }}</span>
                    @empty
                        <span class="wf-kpi__none-text">{{ __('admin.dashboard.no_deliveries_yet') }}</span>
                    @endforelse
                </div>
            </article>
        </div>
    </section>
</x-filament-widgets::widget>
