<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI P0 (2026-10-30) — platform settings (key/value).
 *
 * `is_monetary` marks values denominated in the default currency; the P7
 * default-currency re-denomination job converts exactly those rows.
 * SettingService (app/Services/Functional/Setting) already reads this
 * table's key/value shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value');
            $table->boolean('is_monetary')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()
                ->constrained('admins', 'id', 'fk_settings_updated_by_admin')
                ->nullOnDelete();
            $table->timestamps();
        });

        SchemaConstraints::addCheck('settings', 'chk_settings_key_format', "`key` REGEXP '^[a-z0-9_.]+$'");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
