<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.49.0 (V7 step 2): interview practice. Additive only: new tables, nothing existing is changed.
 *  - career_occupations: the non-technical roles customers can practise for (with an optional India NCO 2015 code).
 *  - interview_questions + interview_question_versions: the question bank. A question is served only while approved;
 *    every wording is a version with its source, rights and the person who approved it.
 *  - interview_sessions + interview_answers: one mock interview with its locked question set and the customer's answers.
 *  - interview_evaluations: the feedback for a session with the model, prompt and rubric versions that produced it.
 *  - usage_ledger: append-only record of every credit granted, held, used, returned or adjusted (first used for mock
 *    interviews; downloads and ATS scans keep their existing counters).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('career_occupations')) {
            Schema::create('career_occupations', function (Blueprint $t) {
                $t->id();
                $t->string('slug', 80)->unique();
                $t->string('name', 120);
                $t->string('family', 80);
                $t->string('industry', 80)->default('');
                $t->string('nco_code', 20)->nullable();
                $t->text('synonyms')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('interview_questions')) {
            Schema::create('interview_questions', function (Blueprint $t) {
                $t->id();
                $t->string('code', 40)->unique();
                $t->string('occupation', 80)->nullable()->index(); // null = suits every role
                $t->string('competency', 40);
                $t->unsignedTinyInteger('difficulty')->default(2);
                $t->string('min_band', 10)->default('fresher');
                $t->string('max_band', 10)->default('12+');
                $t->string('status', 12)->default('draft')->index(); // draft, approved, retired
                $t->unsignedBigInteger('current_version_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('interview_question_versions')) {
            Schema::create('interview_question_versions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('question_id')->index();
                $t->unsignedSmallInteger('version');
                $t->text('text');
                $t->text('guidance');
                $t->text('example')->nullable();
                $t->string('source', 160);
                $t->string('rights', 20)->default('original'); // original, licensed, public
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['question_id', 'version']);
            });
        }
        if (! Schema::hasTable('interview_sessions')) {
            Schema::create('interview_sessions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('occupation', 80);
                $t->string('band', 10);
                $t->string('objective', 20)->default('first_job');
                $t->text('company')->nullable();
                $t->text('questions_json'); // locked at start: [{"q": question id, "v": version id}]
                $t->string('status', 16)->default('in_progress')->index(); // in_progress, submitted, evaluating, evaluated, failed, abandoned
                $t->uuid('purchase_id')->nullable();
                $t->string('grant', 12)->default('plan'); // plan or complimentary
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->string('error', 300)->nullable();
                $t->timestamp('retry_at')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamp('evaluated_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('interview_answers')) {
            Schema::create('interview_answers', function (Blueprint $t) {
                $t->id();
                $t->uuid('session_id')->index();
                $t->unsignedBigInteger('question_version_id');
                $t->unsignedTinyInteger('position');
                $t->text('answer')->nullable();
                $t->timestamps();
                $t->unique(['session_id', 'position']);
            });
        }
        if (! Schema::hasTable('interview_evaluations')) {
            Schema::create('interview_evaluations', function (Blueprint $t) {
                $t->id();
                $t->uuid('session_id')->unique();
                $t->string('model', 60)->default('');
                $t->string('prompt_version', 16)->default('');
                $t->string('rubric_version', 16)->default('');
                $t->string('level', 20)->default('');
                $t->longText('result_json');
                $t->text('challenge')->nullable(); // the customer's note when they think the feedback is wrong
                $t->timestamp('challenged_at')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('usage_ledger')) {
            Schema::create('usage_ledger', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_id')->index();
                $t->uuid('purchase_id')->nullable()->index();
                $t->string('resource', 30); // mock_interview
                $t->string('kind', 12);     // grant, reserve, consume, release, adjust
                $t->integer('amount');       // + adds to the balance, - takes from it
                $t->string('reference', 64)->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('reason', 300)->nullable();
                $t->string('idem', 120)->unique();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['owner_id', 'resource']);
            });
        }
    }

    public function down(): void
    {
        foreach (['usage_ledger', 'interview_evaluations', 'interview_answers', 'interview_sessions', 'interview_question_versions', 'interview_questions', 'career_occupations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
