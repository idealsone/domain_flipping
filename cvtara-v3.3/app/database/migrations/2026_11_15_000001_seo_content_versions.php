<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.45.0 More approvable SEO changes: every approved change to a page is kept as a content version (before and after)
 * that can be undone in one click and is checked on the live page afterwards. Proposals remember who drafted them, so
 * nobody approves their own draft. Pages record when a person last reviewed them (shown on the page, never invented).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_content_versions')) {
            Schema::create('seo_content_versions', function (Blueprint $t) {
                $t->id();
                $t->uuid('proposal_id');
                $t->string('path', 255);
                $t->string('kind', 20);
                $t->json('before_json')->nullable();
                $t->json('after_json')->nullable();
                $t->unsignedBigInteger('applied_by')->nullable();
                $t->timestamp('applied_at')->nullable();
                $t->unsignedBigInteger('reverted_by')->nullable();
                $t->timestamp('reverted_at')->nullable();
                $t->string('verify_status', 20)->default('pending'); // pending, live, not_live, failed
                $t->string('verify_note', 500)->default('');
                $t->timestamp('verified_at')->nullable();
                $t->unsignedTinyInteger('verify_tries')->default(0);
                $t->index(['path', 'id']);
                $t->index(['verify_status', 'applied_at']);
                $t->index('proposal_id');
            });
        }
        if (Schema::hasTable('seo_proposals') && ! Schema::hasColumn('seo_proposals', 'created_by')) {
            Schema::table('seo_proposals', function (Blueprint $t) {
                $t->unsignedBigInteger('created_by')->nullable(); // null = the SEO agent
                $t->uuid('task_id')->nullable();                  // content brief: the task it became
            });
        }
        if (Schema::hasTable('seo_pages') && ! Schema::hasColumn('seo_pages', 'reviewed_at')) {
            Schema::table('seo_pages', function (Blueprint $t) {
                $t->timestamp('reviewed_at')->nullable();
                $t->unsignedBigInteger('reviewed_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_content_versions');
        if (Schema::hasColumn('seo_proposals', 'created_by')) {
            Schema::table('seo_proposals', fn (Blueprint $t) => $t->dropColumn(['created_by', 'task_id']));
        }
        if (Schema::hasColumn('seo_pages', 'reviewed_at')) {
            Schema::table('seo_pages', fn (Blueprint $t) => $t->dropColumn(['reviewed_at', 'reviewed_by']));
        }
    }
};
