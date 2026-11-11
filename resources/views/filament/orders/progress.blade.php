{{-- Order progress stepper (UX redesign round 2, 2026-11-11): where the order
     stands, each completed step with its time. A cancelled order shows the
     steps it reached and then "Cancelled". Display only. --}}
@php
    use App\Enums\OrderStatus;
    $status = $record->status;
    $times = [
        'pending' => $record->created_at,
        'accepted' => $record->accepted_at,
        'assigned' => $record->assigned_at,
        'picked_up' => $record->picked_up_at,
        'delivered' => $record->delivered_at,
    ];
    $order = array_keys($times);
    $cancelled = $status === OrderStatus::Cancelled;
    $reached = $cancelled
        ? count(array_filter($times))
        : array_search($status->value, $order, true) + 1;
    $fmt = fn ($at) => $at?->copy()->setTimezone(\App\Support\Settings\PlatformSettings::timezone())->locale(app()->getLocale())->translatedFormat(\App\Filament\Support\TableDefaults::dateTimeFormat());
@endphp
<ol class="wf-steps" data-cancelled="{{ $cancelled ? 'yes' : 'no' }}" aria-label="{{ __('admin.orders.progress') }}">
    @foreach ($order as $i => $step)
        @php($done = $i < $reached)
        @php($current = ! $cancelled && $i === $reached - 1 && $status !== OrderStatus::Delivered)
        <li class="wf-step" data-state="{{ $current ? 'current' : ($done ? 'done' : 'todo') }}" @if ($current) aria-current="step" @endif>
            <span class="wf-step__dot" aria-hidden="true">
                @if ($done && ! $current)
                    <x-filament::icon icon="heroicon-m-check" class="wf-step__icon" />
                @else
                    {{ $i + 1 }}
                @endif
            </span>
            <span class="wf-step__text">
                <span class="wf-step__label">{{ OrderStatus::from($step)->label() }}</span>
                <span class="wf-step__time">{{ $done ? ($fmt($times[$step]) ?? '—') : '' }}</span>
            </span>
        </li>
    @endforeach
    @if ($cancelled)
        <li class="wf-step" data-state="cancelled">
            <span class="wf-step__dot" aria-hidden="true"><x-filament::icon icon="heroicon-m-x-mark" class="wf-step__icon" /></span>
            <span class="wf-step__text">
                <span class="wf-step__label">{{ OrderStatus::Cancelled->label() }}</span>
                <span class="wf-step__time">{{ $fmt($record->cancellation?->created_at ?? $record->updated_at) }}</span>
            </span>
        </li>
    @endif
</ol>
