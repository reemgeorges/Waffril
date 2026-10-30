{{-- WAFFRLI (2026-11-01): per-currency wallet and payment totals (database values). --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('admin.dashboard_stats.finance')">
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:.875rem;border-collapse:collapse" data-testid="finance-overview">
                <thead>
                    <tr style="opacity:.8">
                        <th style="text-align:start;padding:.5rem;white-space:nowrap">{{ __('admin.dashboard_stats.currency') }}</th>
                        <th style="text-align:end;padding:.5rem;white-space:nowrap">{{ __('admin.dashboard_stats.wallet_balances') }}</th>
                        <th style="text-align:end;padding:.5rem;white-space:nowrap">{{ __('admin.dashboard_stats.wallets') }}</th>
                        <th style="text-align:end;padding:.5rem;white-space:nowrap">{{ __('admin.dashboard_stats.collected') }}</th>
                        <th style="text-align:end;padding:.5rem;white-space:nowrap">{{ __('admin.dashboard_stats.pending_cash') }}</th>
                        <th style="text-align:end;padding:.5rem;white-space:nowrap">{{ __('admin.dashboard_stats.refunded') }}</th>
                        <th style="text-align:end;padding:.5rem;white-space:nowrap">{{ __('admin.dashboard_stats.unsettled_cash') }}</th>
                        <th style="text-align:start;padding:.5rem;white-space:nowrap"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr style="border-top:1px solid rgba(127,127,127,.25)">
                            <td style="padding:.5rem;font-weight:600">{{ $row['code'] }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums">{{ $row['wallet_balances'] }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums">{{ $row['wallets'] }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums">{{ $row['collected'] }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums">{{ $row['pending_cash'] }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums">{{ $row['refunded'] }}</td>
                            <td style="padding:.5rem;text-align:end;font-variant-numeric:tabular-nums">{{ $row['unsettled_cash'] }}</td>
                            <td style="padding:.5rem">
                                <x-filament::badge :color="$row['ledger_consistent'] ? 'success' : 'danger'">
                                    {{ $row['ledger_consistent'] ? __('admin.dashboard_stats.ledger_ok') : __('admin.dashboard_stats.ledger_mismatch') }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="padding:.5rem;opacity:.7">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
