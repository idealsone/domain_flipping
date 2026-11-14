<?php

use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * 3.75.0, owner decision of 14 Nov 2026 ("Same, plus sell Complete Job Kit"): prices stay as they are, and the Complete Job Kit
 * (₹1,299, worth ₹1,797) goes on sale as the top tier. It now carries Pro's allowances (30 tailored resumes, 30 cover letters,
 * 60 ATS scans, 12 mock interviews, 90 days) plus Tara Expert Review and Profile Boost, and ranks above Pro so it wins when both
 * are active. Only the kit's saved settings change; the price and anything else the owner set are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plans = Settings::get('plans', null);
        if (! is_array($plans)) {
            return; // nothing saved: the code defaults already have the kit on sale
        }
        $k = is_array($plans['complete-kit'] ?? null) ? $plans['complete-kit'] : [];
        $k['sale'] = true;
        $k['savedResumes'] = max(30, (int) ($k['savedResumes'] ?? 0));
        $k['savedLetters'] = max(30, (int) ($k['savedLetters'] ?? 0));
        $k['atsScans'] = max(60, (int) ($k['atsScans'] ?? 0));
        $k['mockInterviews'] = max(12, (int) ($k['mockInterviews'] ?? 0));
        $k['accessDays'] = max(90, (int) ($k['accessDays'] ?? 0));
        $k['rank'] = 4;
        $k['perks'] = ['Everything in Pro: 30 tailored resumes, 30 cover letters, 60 ATS Match scans', 'Tara Expert Review of your resume', 'Profile Boost for LinkedIn and Naukri'];
        $plans['complete-kit'] = $k;
        Settings::put('plans', $plans);
    }

    public function down(): void {}
};
