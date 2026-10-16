<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ats_scans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $t->uuid('resume_id')->nullable();
            $t->string('source', 8);
            $t->string('job_url', 1000)->default('');
            $t->string('job_title', 180)->default('');
            $t->string('company', 180)->default('');
            $t->mediumText('job_text');
            $t->unsignedTinyInteger('score')->nullable();
            $t->mediumText('result_json')->nullable();
            $t->string('status', 10)->default('reserved');
            $t->timestamps();
            $t->index(['owner_id', 'created_at']);
        });
        Schema::table('resume_drafts', fn (Blueprint $t) => $t->string('job_url', 1000)->nullable());
        Schema::table('applications', function (Blueprint $t) {
            $t->string('origin', 12)->default('manual');
            $t->uuid('scan_id')->nullable();
            $t->unsignedInteger('resume_credits')->default(0);
            $t->unsignedInteger('letter_credits')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn(['origin', 'scan_id', 'resume_credits', 'letter_credits']));
        Schema::table('resume_drafts', fn (Blueprint $t) => $t->dropColumn('job_url'));
        Schema::dropIfExists('ats_scans');
    }
};
