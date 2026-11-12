<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.61.0 Full mock interview (V5.8 §5, V5.10 §2, V7 D04; owner OK 12 Nov 2026). Additive only:
 *  - interview_sessions.mode: 'standard' (as before) or 'full' (about 60 minutes in four 15-minute parts, one credit).
 *  - interview_sessions.sections_json: the parts of a full mock (title, minutes, first and last question).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $t) {
            if (! Schema::hasColumn('interview_sessions', 'mode')) {
                $t->string('mode', 10)->default('standard');
            }
            if (! Schema::hasColumn('interview_sessions', 'sections_json')) {
                $t->json('sections_json')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive release: nothing is removed on rollback.
    }
};
