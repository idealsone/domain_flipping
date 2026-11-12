<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.57.0 Interview vertical (V7 §16-§20, V5.6-V5.7 §5). Additive only:
 *  - career_occupations: business domain, ISCO-08 code and seniority ladder beside the existing family, industry, NCO
 *    code and synonyms; 12 more non-technical roles and their draft questions are loaded (drafts until approved).
 *  - interview_answers.seconds: time the customer took on an answer (timers are optional).
 *  - company_research: "Research a company" requests, the public page read, a short summary and the practice questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_occupations', function (Blueprint $t) {
            if (! Schema::hasColumn('career_occupations', 'domain')) {
                $t->string('domain', 80)->default('');
            }
            if (! Schema::hasColumn('career_occupations', 'isco_code')) {
                $t->string('isco_code', 10)->nullable();
            }
            if (! Schema::hasColumn('career_occupations', 'seniority')) {
                $t->string('seniority', 160)->default('');
            }
        });
        if (! Schema::hasColumn('interview_answers', 'seconds')) {
            Schema::table('interview_answers', fn (Blueprint $t) => $t->unsignedInteger('seconds')->nullable());
        }
        if (! Schema::hasTable('company_research')) {
            Schema::create('company_research', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('company', 160);
                $t->string('url', 500);
                $t->string('occupation', 80)->nullable();
                $t->string('status', 20); // ready, blocked, failed
                $t->string('page_title', 200)->nullable();
                $t->text('summary')->nullable();
                $t->text('themes_json')->nullable();
                $t->text('questions_json')->nullable();
                $t->string('note', 300)->nullable();
                $t->timestamp('fetched_at')->nullable();
                $t->timestamps();
            });
        }
        \App\Services\Interviews::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('company_research');
    }
};
