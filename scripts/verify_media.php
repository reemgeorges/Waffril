<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$folders = ['products', 'stores', 'categories'];
$expected = 34;
$issues = [];

foreach ($folders as $folder) {
    $dir = $root.'/storage/app/public/demo/photo-catalog/'.$folder;
    $images = glob($dir.'/*.jpg') ?: [];
    echo $folder.': '.count($images)." JPG files\n";
    if (count($images) !== $expected) {
        $issues[] = "{$folder} has ".count($images)." images, expected {$expected}";
    }
    foreach ($images as $path) {
        $info = @getimagesize($path);
        if (!$info || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            $issues[] = 'Invalid JPEG: '.$path;
        }
    }
}

if ($issues) {
    fwrite(STDERR, "FAIL\n".implode("\n", $issues)."\n");
    exit(1);
}

echo "PASS — all 102 JPG images are present and readable.\n";
