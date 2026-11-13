<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3.71.0 Personal mock interviews (owner, 12-13 Nov 2026). Additive only:
 *  - profiles: interview_track ('same' or 'switch'), current_role and switch_skills for people changing jobs;
 *  - interview_sessions: the practice panel, a 30-minute deadline, when results are released and when the customer was told;
 *  - interview_answers: custom_text/custom_guidance for questions made from the customer's own resume, and which panelist asks;
 *  - interview_model_answers: personal model answers for bank questions, made once when asked for and kept.
 * Owner's timing (13 Nov): 30-minute interviews, one a day, results 30 minutes after the interview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $t) {
            if (! Schema::hasColumn('profiles', 'interview_track')) {
                $t->string('interview_track', 12)->default('same');
            }
            if (! Schema::hasColumn('profiles', 'current_role')) {
                $t->string('current_role', 120)->default('');
            }
            if (! Schema::hasColumn('profiles', 'switch_skills')) {
                $t->text('switch_skills')->nullable();
            }
        });
        Schema::table('interview_sessions', function (Blueprint $t) {
            if (! Schema::hasColumn('interview_sessions', 'panel_json')) {
                $t->text('panel_json')->nullable();
            }
            if (! Schema::hasColumn('interview_sessions', 'track')) {
                $t->string('track', 12)->default('same');
            }
            if (! Schema::hasColumn('interview_sessions', 'current_role')) {
                $t->string('current_role', 120)->nullable();
            }
            if (! Schema::hasColumn('interview_sessions', 'deadline_at')) {
                $t->timestamp('deadline_at')->nullable();
            }
            if (! Schema::hasColumn('interview_sessions', 'release_at')) {
                $t->timestamp('release_at')->nullable();
            }
            if (! Schema::hasColumn('interview_sessions', 'notified_at')) {
                $t->timestamp('notified_at')->nullable();
            }
        });
        Schema::table('interview_answers', function (Blueprint $t) {
            if (! Schema::hasColumn('interview_answers', 'custom_text')) {
                $t->text('custom_text')->nullable();
            }
            if (! Schema::hasColumn('interview_answers', 'custom_guidance')) {
                $t->text('custom_guidance')->nullable();
            }
            if (! Schema::hasColumn('interview_answers', 'asked_by')) {
                $t->unsignedTinyInteger('asked_by')->nullable();
            }
        });
        if (! Schema::hasTable('interview_model_answers')) {
            Schema::create('interview_model_answers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('owner_id');
                $t->unsignedBigInteger('question_version_id');
                $t->text('answer');
                $t->timestamp('created_at')->nullable();
                $t->unique(['owner_id', 'question_version_id']);
                $t->index(['owner_id', 'created_at']);
            });
        }
        // Sessions already finished were announced when their feedback was written.
        DB::table('interview_sessions')->where('status', 'evaluated')->whereNull('notified_at')->update(['notified_at' => DB::raw('evaluated_at')]);

        // Owner's new format replaces the earlier saved numbers: 8 questions, one mock a day, the 60-minute full mock off.
        $row = DB::table('site_settings')->where('key', 'interviews')->first();
        if ($row) {
            $s = json_decode((string) $row->value_json, true) ?: [];
            $s = array_merge($s, ['questions' => 8, 'dailySessions' => 1, 'fullMock' => false, 'minutes' => 30, 'releaseDelay' => 30]);
            DB::table('site_settings')->where('key', 'interviews')->update(['value_json' => json_encode($s), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Additive release: nothing is removed on rollback.
    }
};
