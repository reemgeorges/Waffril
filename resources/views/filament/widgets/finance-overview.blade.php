{{-- WAFFRLI (2026-11-01; UX redesign 2026-11-11): per-currency wallet and
     payment totals (database values, never converted between currencies),
     shown at each currency's own precision with thousands separators.
     Styled by the admin theme (.wf-data-table). --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('admin.dashboard_stats.finance')" :description="__('admin.dashboard_stats.finance_help')" icon="heroicon-o-banknotes">
        <div class="wf-flush wf-scroll-x">
            <table class="wf-data-table" data-testid="finance-overview">
                <thead>
                    <tr>
                        <th>{{ __('admin.dashboard_stats.currency') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.collected') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.pending_cash') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.unsettled_cash') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.refunded') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.wallet_balances') }}</th>
                        <th>{{ __('admin.dashboard_stats.ledger') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td><span class="wf-currency-code">{{ $row['code'] }}</span></td>
                            <td class="wf-end wf-num">{{ $row['display']['collected'] }}</td>
                            <td class="wf-end wf-num">{{ $row['display']['pending_cash'] }}</td>
                            <td class="wf-end wf-num" @if ($row['unsettled_cash'] !== '0.0000') data-tone="warning" @endif>{{ $row['display']['unsettled_cash'] }}</td>
                            <td class="wf-end wf-num">{{ $row['display']['refunded'] }}</td>
                            <td class="wf-end wf-num">
                                {{ $row['display']['wallet_balances'] }}
                                <span class="wf-cell-note">{{ trans_choice('admin.dashboard_stats.wallets_count', $row['wallets'], ['count' => $row['wallets']]) }}</span>
                            </td>
                            <td>
                                <x-filament::badge :color="$row['ledger_consistent'] ? 'success' : 'danger'" :icon="$row['ledger_consistent'] ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'">
                                    {{ $row['ledger_consistent'] ? __('admin.dashboard_stats.ledger_ok') : __('admin.dashboard_stats.ledger_mismatch') }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="wf-table-empty">{{ __('admin.dashboard_stats.finance_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
