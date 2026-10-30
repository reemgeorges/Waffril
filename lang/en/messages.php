<?php

/*
 * WAFFRLI P0 (2026-10-30). Only P0 keys are defined here; any other
 * messages.* key used elsewhere keeps resolving to its key exactly as
 * before this file existed.
 */
return [
    'db_check_violation' => 'The submitted data violates a data integrity rule.',
    'currency_default_required_first' => 'Initialize the default currency before adding other currencies.',
    'currency_default_already_initialized' => 'A default currency already exists. Changing the default currency is not available yet.',
    'currency_default_rate_fixed' => 'The default currency exchange rate is always 1.',
    'currency_default_cannot_deactivate' => 'The default currency cannot be deactivated.',
    'delivery_fee_rule_overlap' => 'This distance band overlaps an active band in the same scope.',
    'delivery_fee_rule_area_city_mismatch' => 'The delivery area must belong to the selected city.',
    'delivery_fee_rule_invalid_band' => 'distance_to_km must be greater than distance_from_km.',
    'setting_value_required' => 'The value is required.',
    'setting_monetary_value_invalid' => 'A monetary setting must be a non-negative number with at most 4 decimals.',
    'city_not_served' => 'This location is outside every service city.',
];
