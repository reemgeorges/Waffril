{{-- PLUS request details and the customer's review history (UX overhaul 2026-11-12). --}}
@php
    use App\Filament\Resources\PlusRequests\PlusRequestResource as R;
    $fmt = fn ($at) => $at?->copy()->setTimezone(\App\Support\Settings\PlatformSettings::timezone())->locale(app()->getLocale())->translatedFormat(\App\Filament\Support\TableDefaults::dateTimeFormat());
@endphp
<div class="wf-detail">
    <dl class="wf-detail__grid">
        <div><dt>{{ __('admin.fields.customer') }}</dt><dd>{{ $request->user?->fullName() }} <span class="wf-ltr wf-muted">{{ $request->user?->phone }}</span></dd></div>
        <div><dt>{{ __('admin.fields.type') }}</dt><dd>{{ __('admin.plus.request_'.$request->type) }}</dd></div>
        <div><dt>{{ __('admin.fields.plan') }}</dt><dd>{{ $request->requestedPlan?->name ?? '—' }}</dd></div>
        <div><dt>{{ __('admin.fields.status') }}</dt><dd>
            <x-filament::badge :color="R::STATUS_COLORS[$request->status] ?? 'gray'" :icon="R::STATUS_ICONS[$request->status] ?? null">{{ __('admin.requests.status_'.$request->status) }}</x-filament::badge>
        </dd></div>
        <div><dt>{{ __('admin.plus.requested_at') }}</dt><dd>{{ $fmt($request->created_at) }}</dd></div>
        <div><dt>{{ __('admin.plus.reviewed_by') }}</dt><dd>{{ $request->reviewedBy?->name ?? '—' }} @if ($request->reviewed_at)<span class="wf-muted">· {{ $fmt($request->reviewed_at) }}</span>@endif</dd></div>
        <div class="wf-detail__wide"><dt>{{ __('admin.fields.customer_note') }}</dt><dd>{{ $request->customer_note ?: '—' }}</dd></div>
        <div class="wf-detail__wide"><dt>{{ __('admin.plus.review_note') }}</dt><dd>{{ $request->admin_note ?: '—' }}</dd></div>
    </dl>

    <h3 class="wf-detail__title">{{ __('admin.plus.history') }}</h3>
    <ol class="wf-timeline">
        @foreach ($history as $item)
            <li class="wf-timeline__item" data-current="{{ $item->is($request) ? 'yes' : 'no' }}">
                <span class="wf-timeline__dot" data-tone="{{ ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'][$item->status] ?? '' }}"></span>
                <div>
                    <strong>{{ __('admin.plus.request_'.$item->type) }}</strong>
                    @if ($item->requestedPlan) · {{ $item->requestedPlan->name }} @endif
                    · <x-filament::badge size="sm" :color="R::STATUS_COLORS[$item->status] ?? 'gray'">{{ __('admin.requests.status_'.$item->status) }}</x-filament::badge>
                    <div class="wf-muted">{{ $fmt($item->created_at) }}@if ($item->reviewedBy) · {{ __('admin.plus.reviewed_by') }}: {{ $item->reviewedBy->name }}@endif</div>
                    @if ($item->admin_note)<div class="wf-muted">“{{ $item->admin_note }}”</div>@endif
                </div>
            </li>
        @endforeach
    </ol>
</div>
