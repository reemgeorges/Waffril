<?php

namespace App\Filament\Concerns;

use Livewire\Attributes\On;

/**
 * WAFFRLI (2026-11-03) — re-render when the panel's realtime listener
 * (resources/views/filament/realtime/operations-listener.blade.php) reports
 * an order change on `admin.orders`. The component re-queries the database
 * itself, so permissions and filters apply as usual.
 */
trait RefreshesOnOrderEvents
{
    #[On('waffrli-orders-changed')]
    public function refreshFromRealtime(): void
    {
        // Livewire re-renders after this call.
    }
}
