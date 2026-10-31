<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/**
 * WAFFRLI (2026-11-05): generator tests used to delete docs/openapi and
 * docs/postman in tearDown(), wiping the COMMITTED starter docs on every
 * test run. Snapshot them before the test and put them back, byte for byte,
 * after the test's own cleanup.
 */
trait PreservesGeneratedDocs
{
    /** @var array<string, string> relative path => contents */
    private array $preservedDocs = [];

    protected function snapshotGeneratedDocs(): void
    {
        $this->preservedDocs = [];
        foreach (['docs/openapi', 'docs/postman'] as $dir) {
            if (File::isDirectory(base_path($dir))) {
                foreach (File::allFiles(base_path($dir)) as $file) {
                    $this->preservedDocs[$dir.'/'.$file->getRelativePathname()] = $file->getContents();
                }
            }
        }
    }

    protected function restoreGeneratedDocs(): void
    {
        foreach (['docs/openapi', 'docs/postman'] as $dir) {
            if (File::isDirectory(base_path($dir))) {
                File::deleteDirectory(base_path($dir));
            }
        }
        foreach ($this->preservedDocs as $path => $contents) {
            File::ensureDirectoryExists(dirname(base_path($path)));
            File::put(base_path($path), $contents);
        }
    }
}
