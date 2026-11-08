<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The homepage copy was rewritten in a plainer, more personal voice. Saved copies of the old default homepage text move to the new defaults; anything the owner rewrote is left alone. */
    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'site')->first();
        if (! $row || ! is_array($site = json_decode((string) $row->value_json, true))) {
            return;
        }
        $fresh = json_decode(file_get_contents(resource_path('data/defaults.json')), true)['site'];
        $old = [
            'announce' => 'Create your account, build and preview · Plans from {{from}} · Pay once, no auto-renewal',
            'heroPill' => 'ATS-friendly resumes for Indian job seekers',
            'heroTitle' => 'Land your dream job as a',
            'heroRoles' => ['Data Analyst', 'Software Engineer', 'HR Manager', 'Marketing Lead', 'Product Designer', 'Finance Analyst'],
            'heroLead' => 'Upload the resume you have or build one from a template. CVTara makes it **ATS-friendly**, shows what each job is missing, and creates a tailored resume and cover letter for it, then tracks every application for you.',
            'ctaPrimary' => 'Build my resume →',
            'ctaSecondary' => 'Try the ATS check',
            'ctaFinalTitle' => 'Your next job offer starts with a better resume',
            'ctaFinalText' => 'Upload the resume you have or start from a template. See your ATS-friendly score in minutes.',
            'ctaFinalButton' => 'Create my resume →',
        ];
        $changed = false;
        foreach ($old as $k => $v) {
            // the rotating titles only go when the headline they finish goes too
            if (($site[$k] ?? null) === $v && ($k !== 'heroRoles' || $site['heroTitle'] === $fresh['heroTitle'])) {
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
