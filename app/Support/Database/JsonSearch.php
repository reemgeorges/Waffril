<?php

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WAFFRLI (2026-11-03) — case-insensitive LIKE over translatable JSON
 * columns ({"ar": …, "en": …}). JSON_UNQUOTE() returns a binary collation
 * on MySQL, so a plain `name->en LIKE` is case-sensitive there (but not on
 * SQLite); comparing LOWER() on both sides behaves the same on both.
 */
final class JsonSearch
{
    public static function apply(Builder $query, string $column, string $term, array $locales = ['en', 'ar'], string $boolean = 'and'): Builder
    {
        $like = '%'.mb_strtolower(addcslashes($term, '%_\\')).'%';
        $grammar = DB::connection($query->getModel()->getConnectionName())->getDriverName();
        $column = $query->getModel()->qualifyColumn($column);

        return $query->where(function ($w) use ($column, $locales, $like, $grammar) {
            foreach ($locales as $locale) {
                $expression = $grammar === 'mysql'
                    ? "LOWER(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$locale}\"'))) LIKE ?"
                    : "LOWER(json_extract({$column}, '$.\"{$locale}\"')) LIKE ?";
                $w->orWhereRaw($expression, [$like]);
            }
        }, null, null, $boolean);
    }
}
