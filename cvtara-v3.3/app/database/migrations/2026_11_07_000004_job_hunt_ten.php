<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3.35.2 (owner, 7 Nov 2026): Job Hunt gives 10 tailored resumes and 10 cover letters (was 5 and 5).
 * The saved price list is updated where it still holds 5 and 5, and customers on a running Job Hunt get the same 10 and 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'plans')->first();
        $plans = $row ? json_decode((string) $row->value_json, true) : null;
        if (is_array($plans) && isset($plans['job-hunt'])) {
            $jh = &$plans['job-hunt'];
            if ((int) ($jh['savedResumes'] ?? 0) === 5 && (int) ($jh['savedLetters'] ?? 0) === 5) {
                $jh['savedResumes'] = 10;
                $jh['savedLetters'] = 10;
            }
            if (isset($jh['perks']) && is_array($jh['perks'])) {
                $jh['perks'] = array_map(fn ($p) => $p === '5 tailored resumes and 5 cover letters' ? '10 tailored resumes and 10 cover letters' : $p, $jh['perks']);
            }
            unset($jh);
            \App\Services\Settings::put('plans', $plans);
        }
        foreach (DB::table('purchases')->where('plan', 'job-hunt')->whereIn('status', ['paid', 'partially_refunded', 'created'])->where(fn ($q) => $q->whereNull('access_until')->orWhere('access_until', '>', now()))->get() as $p) {
            $l = json_decode((string) $p->limits_json, true) ?: [];
            if ((int) ($l['savedResumes'] ?? 0) === 5 && (int) ($l['savedLetters'] ?? 0) === 5) {
                $l['savedResumes'] = 10;
                $l['savedLetters'] = 10;
                if (isset($l['perks']) && is_array($l['perks'])) {
                    $l['perks'] = array_map(fn ($x) => $x === '5 tailored resumes and 5 cover letters' ? '10 tailored resumes and 10 cover letters' : $x, $l['perks']);
                }
                DB::table('purchases')->where('id', $p->id)->update(['limits_json' => json_encode($l), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
