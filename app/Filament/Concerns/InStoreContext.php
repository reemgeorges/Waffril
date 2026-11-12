<?php

namespace App\Filament\Concerns;

use App\Filament\Resources\Stores\StoreResource;
use App\Models\Store;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * UX overhaul (2026-11-12): branch and product pages read as part of their
 * store — breadcrumbs "Stores › {store} › Branches|Products › {record}" and a
 * "Back to store" button that opens the store on the matching tab. Display
 * and navigation only; the store relationship is not changed here.
 */
trait InStoreContext
{
    /** Relation-manager tab on the store page: 1 = branches, 2 = products. */
    abstract protected static function storeTab(): string;

    protected function contextStore(): ?Model
    {
        $record = method_exists($this, 'getRecord') && $this->hasRecordContext() ? $this->getRecord() : null;
        if ($record?->store) {
            return $record->store;
        }
        $id = request()->integer('store');

        return $id ? Store::query()->find($id) : null;
    }

    protected function hasRecordContext(): bool
    {
        return property_exists($this, 'record') && $this->record !== null;
    }

    public function getBreadcrumbs(): array
    {
        $store = $this->contextStore();
        if (! $store || ! StoreResource::canViewAny()) {
            return parent::getBreadcrumbs();
        }

        $crumbs = [StoreResource::getUrl('index') => StoreResource::getPluralModelLabel()];
        if (StoreResource::can('update', $store)) {
            $crumbs[StoreResource::getUrl('edit', ['record' => $store])] = $store->name;
            $crumbs[StoreResource::getUrl('edit', ['record' => $store]).'?relation='.static::storeTab()] = static::getResource()::getPluralModelLabel();
        } else {
            $crumbs[] = $store->name;
        }
        $crumbs[] = $this->getBreadcrumb();

        return $crumbs;
    }

    protected function backToStoreAction(): Action
    {
        return Action::make('backToStore')->label(__('admin.stores.back_to_store'))->color('gray')
            ->icon('heroicon-m-building-storefront')
            ->url(fn () => StoreResource::getUrl('edit', ['record' => $this->contextStore()]).'?relation='.static::storeTab())
            ->visible(fn () => $this->contextStore() !== null && StoreResource::can('update', $this->contextStore()));
    }
}
