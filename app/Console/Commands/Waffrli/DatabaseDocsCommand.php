<?php

namespace App\Console\Commands\Waffrli;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * WAFFRLI (2026-11-05) — as-built database documentation, read from the LIVE
 * MySQL 8 schema (INFORMATION_SCHEMA): nothing here is hand-written or
 * invented. Writes docs/waffrli/database/:
 *   - README.md   table inventory: columns, types, PK, FKs (with ON DELETE),
 *                 unique constraints, indexes, CHECK constraints, Spatie
 *                 translatable JSON columns and relationship cardinalities;
 *   - erd.md      Mermaid ER diagrams (full, financial, PLUS, notifications,
 *                 catalog/orders) — GitHub renders them;
 *   - erd-*.mmd   the same diagrams as standalone Mermaid sources.
 *
 * Read-only: it only SELECTs from information_schema. The schema-only SQL
 * export is scripts/db/schema-dump.sh (mysqldump --no-data).
 *
 *   docker compose exec app php artisan waffrli:db-docs
 */
class DatabaseDocsCommand extends Command
{
    public const DIR = 'docs/waffrli/database';

    protected $signature = 'waffrli:db-docs {--connection= : MySQL connection (default: the default connection)}';

    protected $description = 'Generate the as-built database documentation (ERD + table inventory) from the live MySQL schema';

    /** Focused diagrams: title => table-name pattern. */
    protected const GROUPS = [
        'financial' => ['Financial: wallets, payments, ledger, refunds, cash, settlements', '/wallet|payment|ledger|refund|cash|settlement|currenc|financial/'],
        'plus' => ['PLUS membership', '/^plus_|plus/'],
        'notifications' => ['Notifications, push and realtime', '/notification|device_token|push/'],
        'orders' => ['Catalog, carts and orders', '/^(orders?|order_|carts?|cart_|stores?|store_|branch|products?|product_|option|categor|offers?|offer_|favorite|rating|cities|delivery_|drivers?|driver_|addresses|customer_address)/'],
    ];

    public function handle(): int
    {
        $connection = DB::connection($this->option('connection') ?: null);
        if ($connection->getDriverName() !== 'mysql') {
            $this->error('waffrli:db-docs reads INFORMATION_SCHEMA of a MySQL 8 database (current driver: '.$connection->getDriverName().').');

            return self::FAILURE;
        }
        $schema = $connection->getDatabaseName();
        $q = fn (string $sql) => collect($connection->select($sql, [$schema]))->map(fn ($r) => (array) $r);

        $tables = $q('SELECT TABLE_NAME AS t, TABLE_COMMENT AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME')->pluck('c', 't');
        $columns = $q('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS def, EXTRA AS extra, GENERATION_EXPRESSION AS gen FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION')->groupBy('t');
        $indexes = $q('SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME')->groupBy('t');
        $fks = $q('SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, k.CONSTRAINT_NAME AS n, r.DELETE_RULE AS del FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.COLUMN_NAME')->groupBy('t');
        $checks = $q('SELECT tc.TABLE_NAME AS t, cc.CONSTRAINT_NAME AS n, cc.CHECK_CLAUSE AS clause FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = ? AND tc.CONSTRAINT_TYPE = "CHECK" ORDER BY tc.TABLE_NAME, cc.CONSTRAINT_NAME')->groupBy('t');
        $version = (string) ($connection->selectOne('SELECT VERSION() AS v')->v ?? '');
        $translatable = self::translatableColumns();

        $dir = base_path(self::DIR);
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/README.md", $this->inventory($tables, $columns, $indexes, $fks, $checks, $translatable, $version));

        $diagrams = ['full' => ['All tables', $tables->keys()->all()]];
        foreach (self::GROUPS as $key => [$title, $pattern]) {
            $selected = $tables->keys()->filter(fn ($t) => preg_match($pattern, $t))->values()->all();
            // Include the direct FK targets so relationships are visible.
            $targets = collect($selected)->flatMap(fn ($t) => collect($fks->get($t, []))->pluck('rt'))->unique()->all();
            $diagrams[$key] = [$title, collect($selected)->merge($targets)->unique()->sort()->values()->all()];
        }
        $erd = "# WAFFRLI — ER diagrams (as built)\n\n"
            ."Generated by `php artisan waffrli:db-docs` from the live MySQL schema (`{$version}`). Do not edit by hand.\n"
            ."Table details: [README.md](README.md). The approved design baseline is [`../erd.md`](../erd.md).\n\n"
            ."Cardinality: `}o--||` many-to-one (required FK), `}o--o|` many-to-one (nullable FK), `|o--||` one-to-one (unique FK).\n";
        foreach ($diagrams as $key => [$title, $list]) {
            $mermaid = self::mermaid($list, $columns, $fks, $indexes, $key === 'full');
            File::put("{$dir}/erd-{$key}.mmd", $mermaid);
            $erd .= "\n## {$title}\n\n![{$title}](erd-{$key}.svg)\n\n```mermaid\n{$mermaid}```\n";
            $poly = collect(self::polymorphic($columns))->filter(fn ($p) => in_array($p['table'], $list, true));
            if ($poly->isNotEmpty()) {
                $erd .= "\nPolymorphic (no FK): ".$poly->map(fn ($p) => "`{$p['table']}.{$p['name']}_type/_id`")->implode(', ').".\n";
            }
        }
        File::put("{$dir}/erd.md", $erd);

        $this->info('Wrote '.self::DIR.'/README.md, erd.md and '.count($diagrams).' .mmd diagrams ('.$tables->count().' tables, '.$fks->flatten(1)->count().' foreign keys).');

        return self::SUCCESS;
    }

    /** @return list<array{table: string, name: string}> polymorphic {name}_type + {name}_id pairs (no FK by design). */
    protected static function polymorphic(Collection $columns): array
    {
        $pairs = [];
        foreach ($columns as $table => $cols) {
            $names = collect($cols)->pluck('c')->all();
            foreach ($names as $name) {
                if (str_ends_with($name, '_type') && in_array(Str::beforeLast($name, '_type').'_id', $names, true)) {
                    $pairs[] = ['table' => $table, 'name' => Str::beforeLast($name, '_type')];
                }
            }
        }

        return $pairs;
    }

    /** @return array<string, list<string>> table => Spatie translatable JSON columns (from the models). */
    public static function translatableColumns(): array
    {
        $map = [];
        foreach ((new Finder)->files()->in(app_path('Models'))->name('*.php') as $file) {
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            try {
                if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                    continue;
                }
                $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();
                $fields = (fn () => property_exists($this, 'translatable') ? (array) $this->translatable : [])->call($model);
                if ($fields !== []) {
                    $map[$model->getTable()] = array_values(array_unique(array_merge($map[$model->getTable()] ?? [], $fields)));
                }
            } catch (Throwable) {
                continue;
            }
        }
        ksort($map);

        return $map;
    }

    protected function inventory(Collection $tables, Collection $columns, Collection $indexes, Collection $fks, Collection $checks, array $translatable, string $version): string
    {
        $incoming = $fks->flatten(1)->groupBy('rt');
        $out = "# WAFFRLI — database reference (as built)\n\n"
            ."Generated by `php artisan waffrli:db-docs` from INFORMATION_SCHEMA of the live MySQL database (`{$version}`) after all migrations. Do not edit by hand.\n\n"
            ."- ER diagrams: [erd.md](erd.md) (Mermaid; GitHub renders them). Standalone sources: `erd-*.mmd`.\n"
            ."- Schema-only SQL: [schema.sql](schema.sql) (`scripts/db/schema-dump.sh`, `mysqldump --no-data`).\n"
            ."- Design baseline and conventions (money DECIMAL(18,4), rates DECIMAL(24,12), CHECK lists, generated marker columns): [`../erd.md`](../erd.md).\n\n"
            .'**'.$tables->count().' tables, '.$fks->flatten(1)->count().' foreign keys, '.$checks->flatten(1)->count()." CHECK constraints.**\n\n"
            ."## Spatie translatable JSON columns\n\nStored as JSON `{\"ar\": …, \"en\": …}` (spatie/laravel-translatable); the API returns the requested locale.\n\n| Table | Columns |\n|---|---|\n";
        foreach ($translatable as $table => $fields) {
            if ($tables->has($table)) {
                $out .= "| `{$table}` | ".implode(', ', array_map(fn ($f) => "`{$f}`", $fields))." |\n";
            }
        }
        $out .= "\n## Polymorphic relationships (no foreign key by design)\n\n`{name}_type` holds the model class or morph alias and `{name}_id` its key. The database cannot enforce these; the application does.\n\n| Table | Columns |\n|---|---|\n";
        foreach (self::polymorphic($columns) as $pair) {
            $out .= "| `{$pair['table']}` | `{$pair['name']}_type`, `{$pair['name']}_id` |\n";
        }
        $out .= "\n## Tables\n\n| Table | Columns | References | Referenced by |\n|---|---|---|---|\n";
        foreach ($tables as $table => $comment) {
            $out .= "| [`{$table}`](#".Str::slug($table).') | '.count($columns->get($table, [])).' | '.collect($fks->get($table, []))->pluck('rt')->unique()->implode(', ').' | '.collect($incoming->get($table, []))->pluck('t')->unique()->implode(', ')." |\n";
        }

        foreach ($tables as $table => $comment) {
            $tableIndexes = collect($indexes->get($table, []));
            $pk = (string) ($tableIndexes->firstWhere('i', 'PRIMARY')['cols'] ?? '—');
            $uniqueCols = $tableIndexes->where('nu', 0)->pluck('cols')->all();
            $tableFks = collect($fks->get($table, []))->keyBy('c');
            $out .= "\n### {$table}\n\n".($comment ? "{$comment}\n\n" : '')."Primary key: `{$pk}`\n\n| Column | Type | Null | Default | Notes |\n|---|---|---|---|---|\n";
            foreach ($columns->get($table, []) as $col) {
                $notes = [];
                if ($tableFks->has($col['c'])) {
                    $fk = $tableFks[$col['c']];
                    $notes[] = "→ `{$fk['rt']}.{$fk['rc']}` (on delete ".strtolower($fk['del']).')';
                }
                if (in_array($col['c'], $translatable[$table] ?? [], true)) {
                    $notes[] = 'translatable {ar, en}';
                }
                if ($col['gen']) {
                    $notes[] = 'generated: `'.str_replace('|', '\\|', Str::limit((string) $col['gen'], 120)).'`';
                } elseif ($col['extra']) {
                    $notes[] = strtolower((string) $col['extra']);
                }
                $default = $col['def'] === null ? '' : '`'.str_replace('|', '\\|', Str::limit((string) $col['def'], 40)).'`';
                $out .= "| `{$col['c']}` | `".str_replace('|', '\\|', (string) $col['type']).'` | '.($col['nullable'] === 'YES' ? 'yes' : 'no')." | {$default} | ".implode('; ', $notes)." |\n";
            }
            $other = $tableIndexes->reject(fn ($i) => $i['i'] === 'PRIMARY');
            if ($other->isNotEmpty()) {
                $out .= "\nIndexes:\n\n";
                foreach ($other as $index) {
                    $out .= '- '.($index['nu'] ? 'index' : '**unique**')." `{$index['i']}` (".str_replace(',', ', ', (string) $index['cols']).")\n";
                }
            }
            if ($checks->has($table)) {
                $out .= "\nCHECK constraints:\n\n";
                foreach ($checks[$table] as $check) {
                    $out .= "- `{$check['n']}`: `".str_replace(['|', "\n"], ['\\|', ' '], Str::limit((string) $check['clause'], 300))."`\n";
                }
            }
            $relations = [];
            foreach ($tableFks as $fk) {
                $relations[] = "many `{$table}` → one `{$fk['rt']}`".(in_array($fk['c'], $uniqueCols, true) ? ' (one-to-one: unique FK)' : '')." via `{$fk['c']}`";
            }
            foreach ($incoming->get($table, []) as $fk) {
                $relations[] = "one `{$table}` → many `{$fk['t']}` via `{$fk['t']}.{$fk['c']}`";
            }
            if ($relations !== []) {
                $out .= "\nRelationships:\n\n- ".implode("\n- ", array_unique($relations))."\n";
            }
        }

        return $out;
    }

    /** @param list<string> $tables */
    protected static function mermaid(array $tables, Collection $columns, Collection $fks, Collection $indexes, bool $compact): string
    {
        $set = array_flip($tables);
        $out = "erDiagram\n";
        foreach ($tables as $table) {
            $unique = collect($indexes->get($table, []))->where('nu', 0)->reject(fn ($i) => $i['i'] === 'PRIMARY')->pluck('cols')->all();
            foreach ($fks->get($table, []) as $fk) {
                if (! isset($set[$fk['rt']])) {
                    continue;
                }
                $nullable = collect($columns->get($table, []))->firstWhere('c', $fk['c'])['nullable'] ?? 'NO';
                $left = in_array($fk['c'], $unique, true) ? '|o' : '}o';
                $right = $nullable === 'YES' ? 'o|' : '||';
                $out .= "    {$table} {$left}--{$right} {$fk['rt']} : \"{$fk['c']}\"\n";
            }
        }
        if (! $compact) {
            foreach ($tables as $table) {
                $fkCols = collect($fks->get($table, []))->pluck('c')->all();
                $out .= "    {$table} {\n";
                foreach ($columns->get($table, []) as $col) {
                    $type = rtrim(preg_replace('/[^a-z0-9_]+/', '_', strtolower(Str::before((string) $col['type'], ' '))), '_');
                    $key = $col['c'] === 'id' ? ' PK' : (in_array($col['c'], $fkCols, true) ? ' FK' : '');
                    $out .= "        {$type} {$col['c']}{$key}\n";
                }
                $out .= "    }\n";
            }
        }

        return $out;
    }
}
