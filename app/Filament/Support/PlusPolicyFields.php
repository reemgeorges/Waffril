<?php

namespace App\Filament\Support;

use App\Models\PlusMembership;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/** Shared renewal-policy inputs (membership page and request approval). */
final class PlusPolicyFields
{
    public static function make(): array
    {
        return [
            Select::make('renewal_policy')->label(__('admin.fields.renewal_policy'))->required()->live()->options([
                PlusMembership::POLICY_MONTHLY => __('admin.plus.policy_monthly'),
                PlusMembership::POLICY_ONE_TIME => __('admin.plus.policy_one_time'),
                PlusMembership::POLICY_CUSTOM => __('admin.plus.policy_custom'),
            ]),
            TextInput::make('custom_duration_days')->label(__('admin.fields.custom_duration_days'))->integer()->minValue(1)->maxValue(3650)
                ->visible(fn (Get $get) => $get('renewal_policy') === PlusMembership::POLICY_CUSTOM)
                ->required(fn (Get $get) => $get('renewal_policy') === PlusMembership::POLICY_CUSTOM),
        ];
    }
}
