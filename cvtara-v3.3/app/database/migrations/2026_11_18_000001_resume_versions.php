<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.52.0 (V7 step 4, WS-05). Additive only.
 *  - resume_versions: the content a resume had before each save (kind "edit", the last 30 kept per resume), the exact
 *    copy used for a job application (kind "application", always kept) and the copy kept before a restore.
 *  - applications.resume_version_id: the frozen copy of the resume this application used.
 *  - cover_letter_drafts.source_resume_id / source_scan_id / source_application_id: what a letter was made from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resume_versions')) {
            Schema::create('resume_versions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('resume_id')->index();
                $t->unsignedBigInteger('owner_id')->index();
                $t->unsignedInteger('revision');
                $t->string('kind', 12)->default('edit'); // edit, application, restore
                $t->string('title', 180)->default('');
                $t->string('template', 120)->default('');
                $t->longText('content_json');
                $t->uuid('application_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['resume_id', 'created_at']);
            });
        }
        if (! Schema::hasColumn('applications', 'resume_version_id')) {
            Schema::table('applications', fn (Blueprint $t) => $t->uuid('resume_version_id')->nullable());
        }
        Schema::table('cover_letter_drafts', function (Blueprint $t) {
            foreach (['source_resume_id', 'source_scan_id', 'source_application_id'] as $c) {
                if (! Schema::hasColumn('cover_letter_drafts', $c)) {
                    $t->uuid($c)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('cover_letter_drafts', fn (Blueprint $t) => $t->dropColumn(['source_resume_id', 'source_scan_id', 'source_application_id']));
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn('resume_version_id'));
        Schema::dropIfExists('resume_versions');
    }
};
