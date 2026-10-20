<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tara Expert Review is written automatically by Tara AI: the review is stored on the order, with retry bookkeeping. */
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $t) {
            $t->longText('review')->nullable(); // the review text the customer sees and is emailed
            $t->string('reviewer', 20)->nullable(); // tara | owner: who is working on it now
            $t->unsignedTinyInteger('review_attempts')->default(0);
            $t->string('review_error', 300)->nullable(); // why the last automatic attempt failed, shown to the owner
            $t->string('review_resume', 190)->nullable(); // title of the resume that was reviewed
            $t->timestamp('retry_at')->nullable();
        });
        // The old wording promised a recruiter within 48 working hours. Update saved copy only where the owner kept the old default text.
        $row = DB::table('site_settings')->where('key', 'plans')->first();
        $plans = $row ? json_decode((string) $row->value_json, true) : null;
        if (is_array($plans)) {
            $swap = [
                'A working recruiter reviews your resume within 48 working hours' => 'Tara AI reviews your whole resume and sends you a written report',
                'One follow-up question' => 'Delivered to your account and by email',
                'A short summary and your top fixes' => 'ATS issues, a rewritten summary and bullets, and your top fixes',
                'Tara Expert Review by a working recruiter' => 'Tara Expert Review of your resume',
                'Pro, plus a recruiter review and a LinkedIn and Naukri profile rewrite' => 'Pro, plus a Tara Expert Review and a LinkedIn and Naukri profile rewrite',
            ];
            array_walk_recursive($plans, function (&$v) use ($swap) {
                if (is_string($v) && isset($swap[$v])) {
                    $v = $swap[$v];
                }
            });
            DB::table('site_settings')->where('key', 'plans')->update(['value_json' => json_encode($plans), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $t) {
            $t->dropColumn(['review', 'reviewer', 'review_attempts', 'review_error', 'review_resume', 'retry_at']);
        });
    }
};
