<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\On;

/**
 * WAFFRLI (2026-11-16) — re-render a non-order panel page (PLUS, customers,
 * drivers, finance, marketing…) when the realtime listener
 * (resources/views/filament/realtime/operations-listener.blade.php) reports
 * any operational change, or when a business action on this page succeeded
 * (BusinessAction::run). The component re-queries the database itself, so
 * permissions, filters and pagination apply as usual.
 */
trait RefreshesOnOperationalEvents
{
    #[On('waffrli-operations-changed')]
    public function refreshFromOperationalEvent(): void
    {
        // Livewire re-renders after this call.
    }
}
