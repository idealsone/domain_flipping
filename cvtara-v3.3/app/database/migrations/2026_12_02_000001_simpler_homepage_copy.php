<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** 3.70.0 Testers found the homepage headline and intro too wordy (second-language readers, freshers). Saved copies of the
     *  previous default text move to the shorter defaults; anything the owner rewrote is left alone. */
    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'site')->first();
        if (! $row || ! is_array($site = json_decode((string) $row->value_json, true))) {
            return;
        }
        $fresh = json_decode(file_get_contents(resource_path('data/defaults.json')), true)['site'];
        $old = [
            'heroTitle' => 'One good resume, and a sharper copy for every job you apply to.',
            'heroLead' => "Upload the resume you already have, or start from a template. Paste a job from Naukri, LinkedIn or a company careers page, and CVTara shows what the recruiter will look for and what your resume doesn't say yet. Fix it, add a matching cover letter, and download.",
            'ctaPrimary' => 'Start my resume',
            'ctaSecondary' => 'Check my resume against a job',
        ];
        $changed = false;
        foreach ($old as $k => $v) {
            if (($site[$k] ?? null) === $v && isset($fresh[$k])) {
                $site[$k] = $fresh[$k];
                $changed = true;
            }
        }
        if ($changed) {
            DB::table('site_settings')->where('key', 'site')->update(['value_json' => json_encode($site), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
