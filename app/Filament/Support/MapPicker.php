<?php

namespace App\Filament\Support;

use Filament\Forms\Components\ViewField;

/**
 * WAFFRLI (2026-11-16, Phase 3) — map fields for admin forms
 * (resources/views/filament/forms/map-picker.blade.php). The map is a
 * helper only (not saved); the real latitude / longitude / boundary fields
 * keep their own validation.
 */
final class MapPicker
{
    /** Click or drag to set $lat/$lng; with $radius a circle shows the radius in km. */
    public static function point(string $lat = 'lat', string $lng = 'lng', ?string $radius = null): ViewField
    {
        return ViewField::make('map_picker')
            ->label(__('admin.map_picker.label'))
            ->helperText(__('admin.map_picker.point_help'))
            ->view('filament.forms.map-picker', ['mode' => 'point', 'lat' => $lat, 'lng' => $lng, 'radius' => $radius])
            ->dehydrated(false)
            ->columnSpanFull();
    }

    /** Read-only preview of a GeoJSON Polygon field. */
    public static function boundary(string $boundary = 'boundary'): ViewField
    {
        return ViewField::make('map_preview')
            ->label(__('admin.map_picker.preview_label'))
            ->helperText(__('admin.map_picker.preview_help'))
            ->view('filament.forms.map-picker', ['mode' => 'boundary', 'boundary' => $boundary])
            ->dehydrated(false)
            ->columnSpanFull();
    }
}
