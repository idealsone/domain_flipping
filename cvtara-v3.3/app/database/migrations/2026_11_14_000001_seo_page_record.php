<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.44.0 SEO page record and audit history: what each page is for (type, keywords, intent, call to action), what the scan
 * read from it (outline, image ALT coverage, links in) and a dated history of every checklist audit.
 */
return new class extends Migration
{
    private const COLS = ['page_type', 'primary_keyword', 'secondary_keywords', 'intent', 'outline_json', 'images', 'images_no_alt', 'links_in', 'cta', 'signals_json', 'content_status',
        'audit_score', 'audit_status', 'audited_at', 'assigned_agent', 'record_note', 'record_by', 'record_at', 'cwv_json', 'cwv_at'];

    public function up(): void
    {
        if (Schema::hasTable('seo_pages') && ! Schema::hasColumn('seo_pages', 'page_type')) {
            Schema::table('seo_pages', function (Blueprint $t) {
                $t->string('page_type', 30)->default('');
                $t->string('primary_keyword', 190)->default('');
                $t->json('secondary_keywords')->nullable();
                $t->string('intent', 30)->default('');
                $t->json('outline_json')->nullable();        // H2 and H3 headings in order
                $t->unsignedSmallInteger('images')->default(0);
                $t->unsignedSmallInteger('images_no_alt')->default(0);
                $t->unsignedSmallInteger('links_in')->default(0);
                $t->string('cta', 200)->default('');          // the main call to action found on the page
                $t->json('signals_json')->nullable();         // viewport, hreflang, breadcrumbs, pagination links as read
                $t->string('content_status', 20)->default('published');
                $t->unsignedTinyInteger('audit_score')->nullable();
                $t->string('audit_status', 20)->default('');  // good, needs_work, poor
                $t->timestamp('audited_at')->nullable();
                $t->string('assigned_agent', 20)->default('seo');
                $t->string('record_note', 500)->default('');
                $t->unsignedBigInteger('record_by')->nullable();
                $t->timestamp('record_at')->nullable();
                $t->json('cwv_json')->nullable();
                $t->timestamp('cwv_at')->nullable();
            });
        }
        if (! Schema::hasTable('seo_page_audits')) {
            Schema::create('seo_page_audits', function (Blueprint $t) {
                $t->id();
                $t->string('path', 255);
                $t->unsignedTinyInteger('score');
                $t->string('status', 20);
                $t->unsignedSmallInteger('failed')->default(0);
                $t->unsignedSmallInteger('warnings')->default(0);
                $t->json('checks_json');
                $t->string('source', 20)->default('scan'); // scan, manual
                $t->unsignedBigInteger('by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['path', 'id']);
                $t->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_page_audits');
        if (Schema::hasColumn('seo_pages', 'page_type')) {
            Schema::table('seo_pages', fn (Blueprint $t) => $t->dropColumn(self::COLS));
        }
    }
};
