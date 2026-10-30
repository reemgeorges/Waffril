<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P2 catalog (2026-10-31) — turns the demo `categories` table
 * into the global product-category tree WITHOUT losing data:
 *
 *  - name/description move to translatable JSON. Existing plain values
 *    are preserved as the "en" translation (no Arabic is invented).
 *  - slug becomes unique; a duplicate slug keeps its first owner and the
 *    others get "-<id>" appended.
 *  - image becomes optional; parent_id (tree) and sort_order are added.
 *
 * Rollback restores plain text from the "en" translation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->json('name_i18n')->nullable();
            $table->json('description_i18n')->nullable();
        });

        DB::table('categories')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('categories')->where('id', $row->id)->update([
                    'name_i18n' => json_encode(['en' => (string) $row->name], JSON_UNESCAPED_UNICODE),
                    'description_i18n' => $row->description === null ? null : json_encode(['en' => (string) $row->description], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });

        $seen = [];
        DB::table('categories')->orderBy('id')->get(['id', 'slug'])->each(function ($row) use (&$seen) {
            if (isset($seen[$row->slug])) {
                DB::table('categories')->where('id', $row->id)->update(['slug' => $row->slug.'-'.$row->id]);
            }
            $seen[$row->slug] = true;
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->renameColumn('name_i18n', 'name');
            $table->renameColumn('description_i18n', 'description');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->json('name')->nullable(false)->change();
            $table->string('slug', 120)->change();
            $table->string('image')->nullable()->change();
            $table->foreignId('parent_id')->nullable()->constrained('categories', 'id', 'fk_categories_parent')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique('slug', 'uq_categories_slug');
            $table->index('parent_id', 'idx_categories_parent');
        });

        SchemaConstraints::addCheck('categories', 'chk_categories_not_own_parent', 'parent_id IS NULL OR parent_id <> id');
    }

    public function down(): void
    {
        if (SchemaConstraints::isMySql()) {
            DB::statement('ALTER TABLE `categories` DROP CHECK `chk_categories_not_own_parent`');
        }

        Schema::table('categories', function (Blueprint $table) {
            // SQLite cannot drop a foreign key by name; by column it rebuilds the table.
            $table->dropForeign(SchemaConstraints::isMySql() ? 'fk_categories_parent' : ['parent_id']);
            $table->dropUnique('uq_categories_slug');
            $table->dropIndex('idx_categories_parent');
            $table->dropColumn(['parent_id', 'sort_order']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('name_plain')->nullable();
            $table->text('description_plain')->nullable();
        });

        DB::table('categories')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $name = json_decode((string) $row->name, true) ?: [];
                $description = json_decode((string) $row->description, true) ?: [];
                DB::table('categories')->where('id', $row->id)->update([
                    'name_plain' => $name['en'] ?? (reset($name) ?: ''),
                    'description_plain' => $description['en'] ?? (reset($description) ?: ''),
                ]);
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->renameColumn('name_plain', 'name');
            $table->renameColumn('description_plain', 'description');
        });
    }
};
