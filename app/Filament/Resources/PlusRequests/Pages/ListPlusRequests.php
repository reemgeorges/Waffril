<?php

namespace App\Filament\Resources\PlusRequests\Pages;

use App\Filament\Resources\PlusRequests\PlusRequestResource;
use App\Models\PlusRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * UX overhaul (2026-11-12): requests by their real state (PlusRequest::STATUS_*),
 * waiting ones first and counted, so reviewers see what needs a decision.
 */
class ListPlusRequests extends ListRecords
{
    protected static string $resource = PlusRequestResource::class;

    public function getTabs(): array
    {
        $count = fn (string $status) => PlusRequest::query()->where('status', $status)->count() ?: null;
        $tab = fn (string $status) => Tab::make(__("admin.requests.status_{$status}"))
            ->icon(PlusRequestResource::STATUS_ICONS[$status])
            ->modifyQueryUsing(fn (Builder $query) => $query->where('status', $status));

        return [
            PlusRequest::STATUS_PENDING => $tab(PlusRequest::STATUS_PENDING)->badge($count(PlusRequest::STATUS_PENDING))->badgeColor('warning'),
            PlusRequest::STATUS_APPROVED => $tab(PlusRequest::STATUS_APPROVED),
            PlusRequest::STATUS_REJECTED => $tab(PlusRequest::STATUS_REJECTED),
            PlusRequest::STATUS_WITHDRAWN => $tab(PlusRequest::STATUS_WITHDRAWN),
            'all' => Tab::make(__('admin.plus.all_requests'))->icon('heroicon-m-queue-list'),
        ];
    }
}
