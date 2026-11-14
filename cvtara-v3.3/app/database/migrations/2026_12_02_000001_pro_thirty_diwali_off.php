<?php

use App\Services\Promos;
use App\Services\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * 3.69.1, owner decisions of 13 Nov 2026:
 * - Pro is 30 tailored resumes and 30 cover letters for 90 days. The live plan already had 30/30, but its first perk line
 *   still said "20 tailored resumes and 20 cover letters", so the cards contradicted themselves. The saved plan gets 30/30,
 *   90 days and that perk line rewritten; nothing else in the plan changes.
 * - The Diwali offer is switched off (mode "off"). The campaign itself is kept so the owner can reuse it next year.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plans = Settings::get('plans', null);
        if (is_array($plans) && isset($plans['pro']) && is_array($plans['pro'])) {
            $p = $plans['pro'];
            $p['savedResumes'] = 30;
            $p['savedLetters'] = 30;
            $p['accessDays'] = 90;
            if (isset($p['perks']) && is_array($p['perks'])) {
                $p['perks'] = array_map(fn ($x) => is_string($x) ? preg_replace('/\b\d+ tailored resumes and \d+ cover letters\b/i', '30 tailored resumes and 30 cover letters', $x) : $x, $p['perks']);
            }
            $plans['pro'] = $p;
            Settings::put('plans', $plans);
        }

        $campaigns = Promos::all();
        $changed = false;
        foreach ($campaigns as &$c) {
            $isDiwali = ($c['theme'] ?? '') === 'diwali' || stripos((string) ($c['name'] ?? '').' '.($c['heading'] ?? '').' '.($c['template'] ?? ''), 'diwali') !== false;
            if ($isDiwali && ($c['mode'] ?? 'off') !== 'off') {
                $c['mode'] = 'off';
                $changed = true;
            }
        }
        unset($c);
        if ($changed) {
            Promos::put($campaigns);
        }
    }

    public function down(): void {}
};
