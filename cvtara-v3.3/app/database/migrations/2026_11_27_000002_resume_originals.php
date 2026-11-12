<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.61.0 Original uploaded resume file (V7 §11 R1; owner OK 12 Nov 2026: keep 90 days, the customer can delete it).
 * Additive only: the file sits in private storage (never public); this table records it and when it is erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resume_originals')) {
            Schema::create('resume_originals', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('owner_id')->index();
                $t->uuid('resume_id')->nullable()->index();
                $t->string('file_name', 200);
                $t->string('kind', 8); // pdf, docx, doc, txt
                $t->unsignedInteger('size');
                $t->string('sha256', 64);
                $t->string('path', 255);
                $t->timestamp('expires_at')->index();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Additive release: nothing is removed on rollback.
    }
};
