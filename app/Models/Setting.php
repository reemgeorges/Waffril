<?php

namespace App\Models;

/**
 * WAFFRLI P0 (2026-10-30). Platform key/value settings. `value` is stored
 * as JSON so scalars, arrays and objects round-trip unchanged; this is
 * the model the pre-existing App\Services\Functional\Setting\SettingService
 * already expected.
 */
class Setting extends BasePlatformModel
{
    protected $fillable = [
        'key' => 'key',
        'value' => 'value',
        'is_monetary' => 'is_monetary',
        'updated_by_admin_id' => 'updated_by_admin_id',
    ];

    protected $casts = [
        'value' => 'json',
        'is_monetary' => 'boolean',
    ];

    protected $search = ['key'];

    protected array $dynamicFilterColumns = ['key', 'is_monetary'];

    protected array $sortableColumns = ['key', 'created_at'];
}
