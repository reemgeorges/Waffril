<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-04) — marketing messages use spatie/laravel-translatable
 * JSON {ar, en} columns (`title`, `body`) like all other admin-managed
 * content, instead of four *_ar / *_en columns.
 *
 * Data-preserving: every row's existing Arabic and English values are
 * copied into the JSON columns BEFORE the old columns are dropped; down()
 * restores them. Sent messages are history and are not otherwise changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('marketing_broadcasts', 'title')) {
            return;
        }

        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->json('title')->nullable()->after('admin_id');
            $table->json('body')->nullable()->after('title');
        });

        DB::table('marketing_broadcasts')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('marketing_broadcasts')->where('id', $row->id)->update([
                    'title' => json_encode(['ar' => $row->title_ar, 'en' => $row->title_en], JSON_UNESCAPED_UNICODE),
                    'body' => json_encode(['ar' => $row->body_ar, 'en' => $row->body_en], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });

        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'title_ar', 'body_en', 'body_ar']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('marketing_broadcasts', 'title')) {
            return;
        }

        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->string('title_en', 120)->default('');
            $table->string('title_ar', 120)->default('');
            $table->string('body_en', 500)->default('');
            $table->string('body_ar', 500)->default('');
        });

        DB::table('marketing_broadcasts')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $title = json_decode((string) $row->title, true) ?: [];
                $body = json_decode((string) $row->body, true) ?: [];
                DB::table('marketing_broadcasts')->where('id', $row->id)->update([
                    'title_en' => mb_substr((string) ($title['en'] ?? ''), 0, 120), 'title_ar' => mb_substr((string) ($title['ar'] ?? ''), 0, 120),
                    'body_en' => mb_substr((string) ($body['en'] ?? ''), 0, 500), 'body_ar' => mb_substr((string) ($body['ar'] ?? ''), 0, 500),
                ]);
            }
        });

        Schema::table('marketing_broadcasts', function (Blueprint $table) {
            $table->dropColumn(['title', 'body']);
        });
    }
};
