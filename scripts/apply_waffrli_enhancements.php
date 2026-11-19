<?php

declare(strict_types=1);

/**
 * SAFE CODE PATCHER for the exact WAFFRLI main source inspected 2026-11-19.
 * It does not connect to the database or touch .env; it rejects source drift.
 * New files and images must be copied from this ZIP before running.
 * Usage: php scripts/apply_waffrli_enhancements.php
 */
$root = dirname(__DIR__);

$replace = static function (string $old, string $new): array {
    return [$old, $new];
};

$changes = [
    'app/Models/PlusPlan.php' => [
        $replace(
            "        'free_deliveries_per_cycle' => 'free_deliveries_per_cycle',",
            "        'free_deliveries_per_cycle' => 'free_deliveries_per_cycle',\n        'free_deliveries_override' => 'free_deliveries_override',"
        ),
        $replace(
            "        'free_deliveries_per_cycle' => 'integer',",
            "        'free_deliveries_per_cycle' => 'integer',\n        'free_deliveries_override' => 'integer',"
        ),
    ],
    'app/Services/Plus/PlusService.php' => [
        $replace(
            "            // 2026-11-12: the platform-wide allowance (admin setting, default 10)\n            // replaces the per-plan value; an approved member override still wins.\n            'free_deliveries_total' => \$membership->free_deliveries_override ?? self::freeDeliveriesLimit(),",
            "            // Priority: explicitly approved member override, then optional\n            // per-plan quota, then the platform default. Zero is meaningful.\n            // Confirmed cycles keep their immutable benefit snapshot.\n            'free_deliveries_total' => \$membership->free_deliveries_override\n                ?? \$plan->free_deliveries_override\n                ?? self::freeDeliveriesLimit(),"
        ),
    ],
    'app/Filament/Resources/PlusPlans/PlusPlanResource.php' => [
        $replace(
            "                    // 2026-11-12: new cycles use the platform setting (Settings → PLUS); kept read-only.\n                    TextInput::make('free_deliveries_per_cycle')->label(__('admin.fields.free_deliveries_per_cycle'))->integer()->default(0)\n                        ->disabled()->dehydrated(false)->helperText(__('admin.plus.free_deliveries_global_help')),
",
            "                    // NULL = platform default; explicit 0 = no free deliveries for this plan.\n                    TextInput::make('free_deliveries_override')->label(__('admin.plus.plan_free_deliveries'))\n                        ->integer()->minValue(0)->maxValue(100)\n                        ->helperText(__('admin.plus.plan_free_deliveries_help')),
"
        ),
        $replace(
            "TextColumn::make('free_deliveries_per_cycle')->label(__('admin.fields.free_deliveries_per_cycle'))->icon('heroicon-m-truck')\n                            ->formatStateUsing(fn (\$state) => trans_choice('admin.table.free_per_cycle', (int) \$state, ['count' => (int) \$state])),",
            "TextColumn::make('free_deliveries_override')->label(__('admin.plus.plan_free_deliveries'))->icon('heroicon-m-truck')\n                            ->placeholder(__('admin.plus.platform_default'))\n                            ->formatStateUsing(fn (\$state) => \$state === null\n                                ? __('admin.plus.platform_default')\n                                : trans_choice('admin.table.free_per_cycle', (int) \$state, ['count' => (int) \$state])),"
        ),
    ],
    'app/Services/Order/CheckoutService.php' => [
        $replace(
            "        return 'WF'.now()->format('ymd').'-'.strtoupper(Str::random(6));",
            "        // Compact public reference, 12 chars: WF + YYMMDD + 4 random.\n        // Uniqueness is enforced in createOrderRow() with collision retries.\n        // Existing order references and internal numeric IDs remain unchanged.\n        return 'WF'.now()->format('ymd').strtoupper(Str::random(4));"
        ),
    ],
    'routes/api/v1/customer.php' => [
        $replace(
            "use App\\Http\\Controllers\\Customer\\CustomerCatalogController;",
            "use App\\Http\\Controllers\\Customer\\CustomerCatalogController;\nuse App\\Http\\Controllers\\Customer\\CustomerHomeSliderController;"
        ),
        $replace(
            "        Route::get('store-categories', [CustomerCatalogController::class, 'storeCategories']);",
            "        Route::get('sliders', CustomerHomeSliderController::class);\n        Route::get('store-categories', [CustomerCatalogController::class, 'storeCategories']);"
        ),
    ],
];

foreach (['ar' => [
    'resource' => "        'home_slider' => ['singular' => 'سلايدر الرئيسية', 'plural' => 'سلايدرات الرئيسية'],",
    'plus' => [
        "        'plan_free_deliveries' => 'التوصيلات المجانية لهذه الخطة',",
        "        'plan_free_deliveries_help' => 'اتركه فارغاً لاستخدام إعداد المنصة. اكتب 0 لمنع التوصيل المجاني بهذه الخطة. ينطبق على الدورات الجديدة فقط، واستثناء الزبون أولويته أعلى.',",
        "        'platform_default' => 'حسب إعداد المنصة',",
    ],
    'sliders' => [
        "        'content' => 'بنرات الشاشة الرئيسية',",
        "        'image_help' => 'صورة أفقية بنسبة 16:9 تقريباً، JPG/PNG/WebP، حد أقصى 4MB. تُحفظ في public disk.',",
        "        'store_help' => 'اختياري: اختر متجراً لفتح أحد فروعه المتاحة بمدينة العميل. اتركه فارغاً لبنر بدون رابط.',",
        "        'global_banner' => 'بنر عام (بدون متجر)',",
        "        'active' => 'فعّال',",
        "        'inactive' => 'متوقف',",
    ],
    'title' => 'العنوان',
    'subtitle' => 'الوصف المختصر',
], 'en' => [
    'resource' => "        'home_slider' => ['singular' => 'Home slider', 'plural' => 'Home sliders'],",
    'plus' => [
        "        'plan_free_deliveries' => 'Free deliveries for this plan',",
        "        'plan_free_deliveries_help' => 'Leave blank to use the platform setting. Enter 0 to offer no free deliveries. Applies to NEW cycles only; member exceptions take priority.',",
        "        'platform_default' => 'Platform default',",
    ],
    'sliders' => [
        "        'content' => 'Home screen banners',",
        "        'image_help' => 'Landscape picture, approximately 16:9, JPG/PNG/WebP, up to 4 MB; saved to public storage.',",
        "        'store_help' => 'Optional: choose a store to open an available branch in the customer city. Leave empty for an informational banner.',",
        "        'global_banner' => 'General banner (no store link)',",
        "        'active' => 'Active',",
        "        'inactive' => 'Inactive',",
    ],
    'title' => 'Title',
    'subtitle' => 'Subtitle',
]] as $locale => $translations) {
    $changes["lang/{$locale}/admin.php"] = [
        $replace(
            "    'resources' => [\n",
            "    'resources' => [\n{$translations['resource']}\n"
        ),
        $replace(
            "    'fields' => [\n",
            "    'fields' => [\n        'title' => '{$translations['title']}',\n        'subtitle' => '{$translations['subtitle']}',\n"
        ),
        $replace(
            "    'plus' => [\n        'free_deliveries_global_help' =>",
            "    'plus' => [\n".implode("\n", $translations['plus'])."\n        'free_deliveries_global_help' =>"
        ),
        $replace(
            "    'offers' => [\n        'all_branches' =>",
            "    'sliders' => [\n".implode("\n", $translations['sliders'])."\n    ],\n    'offers' => [\n        'all_branches' =>"
        ),
    ];
}

$contents = [];
$replaced = [];
foreach ($changes as $name => $replacements) {
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
    if (! is_file($path)) {
        fwrite(STDERR, "MISSING source file: {$name}\n");
        exit(2);
    }
    $original = file_get_contents($path);
    // Match GitHub LF source while preserving Windows CRLF files.
    $lineEnding = str_contains($original, "\r\n") ? "\r\n" : "\n";
    $updated = str_replace("\r\n", "\n", $original);
    foreach ($replacements as [$from, $to]) {
        $count = substr_count($updated, $from);
        if ($count !== 1) {
            fwrite(STDERR, "STOP: {$name} expected exactly one source marker, got {$count}. Your code may differ from the inspected main; no changes were applied.\n");
            exit(3);
        }
        $updated = str_replace($from, $to, $updated);
    }
    $contents[$path] = [$original, $lineEnding === "\r\n" ? str_replace("\n", "\r\n", $updated) : $updated];
    $replaced[] = $name;
}

$backup = $root.'/storage/app/waffrli-patch-backups/'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
foreach ($contents as $path => [$old, $new]) {
    $relative = substr($path, strlen($root) + 1);
    $saveTo = $backup.DIRECTORY_SEPARATOR.$relative;
    if (! is_dir(dirname($saveTo)) && ! mkdir(dirname($saveTo), 0775, true) && ! is_dir(dirname($saveTo))) {
        fwrite(STDERR, "Could not create backup directory {$backup}\n");
        exit(4);
    }
    if (file_put_contents($saveTo, $old) === false) {
        fwrite(STDERR, "Could not back up {$relative}; stopping.\n");
        exit(4);
    }
}
foreach ($contents as $path => [$old, $new]) {
    if (file_put_contents($path, $new) === false) {
        fwrite(STDERR, "WRITE FAILED: {$path}. Restore originals from {$backup}.\n");
        exit(5);
    }
}
fwrite(STDOUT, "SUCCESS: updated ".count($contents)." existing files.\n".
    "Backup: {$backup}\n".
    "New PHP files, migrations, images and Bruno request are supplied separately in the ZIP.\n".
    "Next: php artisan migrate, seed WaffrliHomeSliderSeeder, regenerate API docs.\n");
