{{-- WAFFRLI (2026-11-01): per-currency wallet and payment totals (database values).
     Styled by the admin theme (.wf-data-table, redesign 2026-11-11). --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('admin.dashboard_stats.finance')">
        <div class="wf-flush">
            <table class="wf-data-table" data-testid="finance-overview">
                <thead>
                    <tr>
                        <th>{{ __('admin.dashboard_stats.currency') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.wallet_balances') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.wallets') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.collected') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.pending_cash') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.refunded') }}</th>
                        <th class="wf-end">{{ __('admin.dashboard_stats.unsettled_cash') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td><span class="wf-ltr" style="font-weight:600">{{ $row['code'] }}</span></td>
                            <td class="wf-end">{{ $row['wallet_balances'] }}</td>
                            <td class="wf-end">{{ $row['wallets'] }}</td>
                            <td class="wf-end">{{ $row['collected'] }}</td>
                            <td class="wf-end">{{ $row['pending_cash'] }}</td>
                            <td class="wf-end">{{ $row['refunded'] }}</td>
                            <td class="wf-end">{{ $row['unsettled_cash'] }}</td>
                            <td>
                                <x-filament::badge :color="$row['ledger_consistent'] ? 'success' : 'danger'">
                                    {{ $row['ledger_consistent'] ? __('admin.dashboard_stats.ledger_ok') : __('admin.dashboard_stats.ledger_mismatch') }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="color:var(--wf-text-3)">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
