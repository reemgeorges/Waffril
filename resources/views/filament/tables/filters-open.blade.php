{{-- WAFFRLI (2026-11-16, Phase 2): table filters sit above the table in a
     collapsible panel (App\Filament\Support\TableDefaults). Filament starts
     that panel closed; here it opens once per table when the screen is wide
     (≥ 1024 px) or a filter is already active, so filters are visible where
     there is room, while phones keep the table first behind a "Filters"
     button. A panel the admin closed stays closed. Display only. --}}
<script data-navigate-once>
    (() => {
        const WIDE = 1024;
        const open = () => {
            if (! window.Alpine) { return; }
            document.querySelectorAll('[x-data^="filamentTable("]').forEach((table) => {
                if (table.dataset.wfFiltersInit || ! table.querySelector(':scope .fi-ta-filters-above-content-ctn')) { return; }
                let data;
                try { data = window.Alpine.$data(table); } catch (e) { return; }
                if (! data || ! ('areFiltersOpen' in data)) { return; }
                table.dataset.wfFiltersInit = '1';
                const active = table.querySelector('.fi-ta-filter-indicators') !== null;
                if (window.innerWidth >= WIDE || active) { data.areFiltersOpen = true; }
            });
        };
        let timer = null;
        const soon = () => { clearTimeout(timer); timer = setTimeout(open, 60); };
        document.addEventListener('alpine:initialized', soon);
        document.addEventListener('livewire:navigated', soon);
        new MutationObserver(soon).observe(document.documentElement, { childList: true, subtree: true });
        soon();
    })();
</script>
