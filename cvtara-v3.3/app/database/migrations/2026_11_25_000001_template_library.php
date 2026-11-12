<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.59.0 Template library (V7 §15, V5.0 §5-§7). Additive only:
 *  - template_library: one row per resume / cover-letter design with licence, source, author, version, preview,
 *    export formats, the text-reading test result and its publishing status. Built-in designs are listed as
 *    CVTara originals; imported designs arrive as drafts.
 *  - template_versions: every version of an imported design, so a change never loses the one before it.
 *  - template_packages: each uploaded ZIP package with its manifest and validation report.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('template_library')) {
            Schema::create('template_library', function (Blueprint $t) {
                $t->string('slug', 100)->primary();
                $t->string('kind', 10); // resume, letter
                $t->string('name', 160);
                $t->string('base', 100)->default('');     // built-in design this one renders with
                $t->string('category', 80)->default('');
                $t->unsignedInteger('version')->default(1);
                $t->string('licence', 160)->default('');
                $t->string('source', 200)->default('');
                $t->string('author', 160)->default('');
                $t->string('preview', 255)->default('');
                $t->json('compat_json')->nullable();       // export formats
                $t->boolean('ats_layout')->default(false); // the design is meant to be text-first
                $t->boolean('single_column')->default(true);
                $t->boolean('test_passed')->nullable();
                $t->json('test_json')->nullable();
                $t->unsignedInteger('tested_version')->nullable();
                $t->timestamp('tested_at')->nullable();
                $t->string('status', 12)->default('published'); // published, draft, rejected, retired
                $t->string('origin', 10)->default('builtin');   // builtin, package
                $t->unsignedBigInteger('package_id')->nullable();
                $t->string('content_hash', 64)->default('');
                $t->json('definition_json')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
                $t->index(['kind', 'status']);
            });
        }
        if (! Schema::hasTable('template_versions')) {
            Schema::create('template_versions', function (Blueprint $t) {
                $t->id();
                $t->string('slug', 100)->index();
                $t->unsignedInteger('version');
                $t->string('content_hash', 64);
                $t->json('definition_json');
                $t->unsignedBigInteger('package_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['slug', 'version']);
            });
        }
        if (! Schema::hasTable('template_packages')) {
            Schema::create('template_packages', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160)->default('');
                $t->string('vendor', 160)->default('');
                $t->string('schema_version', 20)->default('');
                $t->string('file_hash', 64)->index();
                $t->json('manifest_json')->nullable();
                $t->json('report_json')->nullable();
                $t->string('status', 12)->default('rejected'); // imported, rejected
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
            });
        }
        \App\Services\TemplateLibrary::sync(); // built-in designs listed as CVTara originals, tested by the scheduler
    }

    public function down(): void
    {
        Schema::dropIfExists('template_packages');
        Schema::dropIfExists('template_versions');
        Schema::dropIfExists('template_library');
    }
};
