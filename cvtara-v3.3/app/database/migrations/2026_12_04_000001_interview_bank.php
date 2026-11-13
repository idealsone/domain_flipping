<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.73.0 The large interview question bank (owner, 12-14 Nov 2026). Additive only:
 *  - interview_bank_patterns: the question patterns and whether the owner approved each one (all start as drafts);
 *  - interview_practised: questions a customer ticked as practised, for progress by category;
 *  - career_occupations: the catalogue's 352 roles are added (existing roles and the owner's edits are kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('interview_bank_patterns')) {
            Schema::create('interview_bank_patterns', function (Blueprint $t) {
                $t->string('code', 8)->primary();
                $t->string('kind', 8); // skill = asked for each skill of a role; role = asked once per role
                $t->string('competency', 40);
                $t->string('category', 20);
                $t->text('text');
                $t->string('status', 12)->default('draft')->index(); // draft, approved, withdrawn
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('interview_practised')) {
            Schema::create('interview_practised', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_id')->index();
                $t->string('code', 40);
                $t->timestamp('created_at')->nullable();
                $t->unique(['owner_id', 'code']);
            });
        }
        \App\Services\InterviewBank::syncPatterns();
        \App\Services\InterviewBank::syncRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_practised');
        Schema::dropIfExists('interview_bank_patterns');
    }
};
