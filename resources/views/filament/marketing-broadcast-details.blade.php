@php
    $stillProcessing = in_array($broadcast->status, [\App\Models\MarketingBroadcast::STATUS_QUEUED, \App\Models\MarketingBroadcast::STATUS_SENDING], true);
    $recipients = $stillProcessing && $broadcast->total_count !== null
        ? __('admin.marketing.progress', [
            'done' => number_format((int) $broadcast->processed_count),
            'total' => number_format((int) $broadcast->total_count),
        ])
        : ($stillProcessing ? '—' : number_format((int) $broadcast->recipients_count));
    $stored = $broadcast->stored_count === null ? '—' : number_format((int) $broadcast->stored_count);
    $accepted = $broadcast->push_enabled && $broadcast->stored_count !== null
        ? number_format((int) $broadcast->push_accepted_count)
        : '—';
@endphp

<div class="space-y-5">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ([
            [__('admin.marketing.recipients'), $recipients],
            [__('marketing_table.in_app_short'), $stored],
            [__('marketing_table.firebase_short'), $accepted],
        ] as [$label, $value])
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('marketing_table.message_content') }}</h3>
        <p class="whitespace-pre-line rounded-xl bg-gray-50 p-4 text-sm text-gray-800 dark:bg-white/5 dark:text-gray-200">{{ $broadcast->body }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('admin.marketing.audience') }}:
            {{ __('admin.marketing.recipients_'.($broadcast->recipient_type ?: \App\Models\MarketingBroadcast::RECIPIENTS_CUSTOMERS)) }}
            · {{ $broadcast->city?->name ?? __('admin.marketing.scope_all') }}
        </p>
    </div>

    <div class="space-y-2">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('marketing_table.delivery_details') }}</h3>
        <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 px-4 dark:divide-white/10 dark:border-white/10">
            @foreach ($broadcast->deliveryLines() as $line)
                <div class="py-3 text-sm text-gray-700 dark:text-gray-200">{{ $line }}</div>
            @endforeach
        </div>
        <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('admin.marketing.accepted_help') }}</p>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        {{ __('admin.marketing.sent_by') }}: {{ $broadcast->admin?->name ?? '—' }}
        · {{ __('admin.fields.created_at') }}:
        {{ $broadcast->created_at ? \App\Support\Time\AdminTimezone::format($broadcast->created_at, \App\Filament\Support\TableDefaults::dateTimeFormat()) : '—' }}
    </p>
</div>
