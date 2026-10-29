<?php

namespace App\Models;

use App\Models\BaseModel;

use Illuminate\Database\Eloquent\Model;

class Test extends BaseModel
{
    protected $fillable = [
        'name' => 'name',
        'description' => 'description',
        'image' => 'image',
        // Phase 1 (2026-10-25): is_active added by the additive migration
        // database/migrations/2026_10_25_120000_add_lifecycle_columns_to_users_tests_categories_tables.php
        'is_active' => 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    //
}
