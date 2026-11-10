{{-- WAFFRLI dispatch B4 (2026-11-10): live delivery-dispatch status of an order.
     Polls the page (refreshDispatch) while a dispatch is running; the
     countdown is informational, the server's expires_at decides. --}}
@php($d = $panel)
<div data-testid="dispatch-panel"
     @if (in_array($d['state'], ['offering', 'choosing'], true)) wire:poll.5s="refreshDispatch"
     @elseif ($d['state'] === 'no_driver') wire:poll.15s="refreshDispatch" @endif
     style="display:flex;flex-direction:column;gap:.75rem;font-size:.875rem">

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem">
        <x-filament::badge :color="$d['state_color']" data-testid="dispatch-state">{{ $d['state_label'] }}</x-filament::badge>
        @if ($d['needs_attention'])
            <x-filament::badge color="danger" icon="heroicon-o-exclamation-triangle">{{ __('admin.dispatch.needs_attention') }}</x-filament::badge>
        @endif
        @unless ($d['enabled'])
            <x-filament::badge color="gray">{{ __('admin.dispatch.offers_disabled') }}</x-filament::badge>
        @endunless
        <span style="opacity:.75">
            {{ __('admin.dispatch.round_attempts', ['round' => $d['round'], 'attempts' => $d['attempts'], 'max' => $d['max_attempts']]) }}
        </span>
    </div>

    @if ($d['pending'])
        <div data-testid="dispatch-current-offer"
             style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.5rem;padding:.75rem;border:1px solid rgba(127,127,127,.25);border-radius:.75rem">
            <div><div style="opacity:.7">{{ __('admin.dispatch.current_driver') }}</div><div style="font-weight:600">{{ $d['pending']['driver'] }}</div></div>
            <div><div style="opacity:.7">{{ __('admin.dispatch.source') }}</div><div>{{ $d['pending']['source'] }} · #{{ $d['pending']['attempt'] }}</div></div>
            <div><div style="opacity:.7">{{ __('admin.dispatch.offered_at') }}</div><div>{{ $d['pending']['offered_at']->format('H:i:s') }}</div></div>
            <div><div style="opacity:.7">{{ __('admin.dispatch.expires_at') }}</div><div>{{ $d['pending']['expires_at']->format('H:i:s') }}</div></div>
            <div>
                <div style="opacity:.7">{{ __('admin.dispatch.remaining') }}</div>
                <div style="font-weight:700;font-variant-numeric:tabular-nums" data-testid="dispatch-remaining"
                     x-data="{ left: {{ (int) $d['pending']['remaining_seconds'] }} }"
                     x-init="const t = setInterval(() => { left = Math.max(0, left - 1); if (left === 0) clearInterval(t) }, 1000)"
                     x-text="left + ' s'">{{ (int) $d['pending']['remaining_seconds'] }} s</div>
            </div>
        </div>
    @endif

    @if ($d['last'])
        <div data-testid="dispatch-last-outcome">
            <span style="opacity:.7">{{ __('admin.dispatch.last_outcome') }}</span>
            <x-filament::badge :color="$d['last']['status_color']" style="display:inline-flex">{{ $d['last']['status'] }}</x-filament::badge>
            {{ $d['last']['driver'] }}@if ($d['last']['reason']) — {{ $d['last']['reason'] }}@endif
            @if ($d['last']['at']) <span style="opacity:.7">({{ $d['last']['at']->format('H:i:s') }})</span>@endif
        </div>
    @endif

    <div style="opacity:.85" data-testid="dispatch-automation">
        @switch($d['state'])
            @case('offering')
            @case('choosing')
                {{ __('admin.dispatch.auto_on_decline', ['seconds' => $d['timeout_seconds']]) }}
                @break
            @case('no_driver')
                {{ __('admin.dispatch.no_driver_since', ['at' => $d['no_driver_since']?->format('H:i')]) }}
                @if ($d['alerted_at']) {{ __('admin.dispatch.admins_alerted') }} @endif
                @if ($d['auto_recovery'])
                    {{ __('admin.dispatch.recovery_on', ['used' => $d['auto_rounds_used'], 'max' => $d['max_auto_rounds'], 'at' => $d['next_auto_round_at']?->format('H:i')]) }}
                @else
                    {{ __('admin.dispatch.recovery_off') }}
                @endif
                @break
            @case('paused')
                {{ __('admin.dispatch.paused_help') }}
                @break
            @case('not_started')
                {{ __('admin.dispatch.not_started_help') }}
                @break
            @default
                {{ __('admin.dispatch.closed_help') }}
        @endswitch
    </div>
</div>
