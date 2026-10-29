<?php

namespace App\Console\Commands\CodeAfterMigrate;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Base scaffolding logic for introspecting database schema
 */
abstract class BaseScaffoldCommand extends Command
{
    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    /**
     * Fetch column metadata for a given table
     */
    protected function getColumns(string $table): array
    {
        $database = DB::getDatabaseName();
        return DB::select(
            "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, COLUMN_KEY
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            [$database, $table]
        );
    }

    /**
     * Fetch foreign key metadata for a given table
     */
    protected function getForeignKeys(string $table): array
    {
        $database = DB::getDatabaseName();
        $rows = DB::select(
            "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$database, $table]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row->COLUMN_NAME] = [
                'table'  => $row->REFERENCED_TABLE_NAME,
                'column' => $row->REFERENCED_COLUMN_NAME,
            ];
        }
        return $map;
    }

    protected function handelModelScope(string $modelInput) : string
    {
        if (! Str::contains($modelInput, '\\\\')) {
            // Ensure StudlyCase
            $modelInput = Str::studly($modelInput);
            $modelClass = "App\\Models\\{$modelInput}";
        } else {
            $modelClass = $modelInput;
        }
        return $modelClass;
    }
}
