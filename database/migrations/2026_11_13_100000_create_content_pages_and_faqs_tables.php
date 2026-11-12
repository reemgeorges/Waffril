<?php

use App\Support\Database\SchemaConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFFRLI (2026-11-12) — app content managed from the admin panel:
 *
 *  - content_pages: long bilingual texts identified by a fixed key
 *    (currently `terms`, Terms & Conditions). Published or draft; every
 *    change to a published text bumps `version` so the app can tell a
 *    customer the terms changed. No consent is recorded here.
 *  - faqs: bilingual questions and answers, ordered, published or draft.
 *
 * Additive only: two new tables, no existing table is changed. Soft deletes
 * like the rest of the catalogue (BaseModel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique('uq_content_pages_key');
            $table->json('title');
            $table->json('body');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_admin_id')->nullable()
                ->constrained('admins', 'id', 'fk_content_pages_admin')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->json('question');
            $table->json('answer');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()
                ->constrained('admins', 'id', 'fk_faqs_admin')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_published', 'sort_order'], 'idx_faqs_published_order');
        });

        SchemaConstraints::addCheck('content_pages', 'chk_content_pages_key', "`key` IN ('terms')");
        SchemaConstraints::addCheck('content_pages', 'chk_content_pages_version', 'version >= 1');
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('content_pages');
    }
};
