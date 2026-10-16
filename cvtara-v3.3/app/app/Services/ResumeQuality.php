<?php

namespace App\Services;

/**
 * Job-independent "ATS friendliness" of a resume: can a tracking system read it, does it have the sections recruiters expect,
 * and is it written the way hiring teams like (action verbs, numbers, enough skills). It does not look at any job description.
 */
class ResumeQuality
{
    private const ACTION = '/^\W*(built|developed|delivered|implemented|designed|created|led|managed|coordinated|screened|sourced|analy[sz]ed|improved|reduced|increased|maintained|automated|deployed|tested|prepared|supported|taught|mentored|conducted|launched|wrote|resolved|produced|reported|achieved|drove|owned|negotiated|trained|streamlined|generated|optimi[sz]ed|handled|organi[sz]ed|planned|executed|monitored|collaborated|assisted|established|completed|processed|ran|performed|presented|initiated|expanded|won|saved)\b/iu';

    /** @return array{score:int,checks:list<array{key:string,label:string,got:int,max:int,tip:string}>,tips:list<string>,level:string} */
    public static function score(array $d): array
    {
        $p = $d['personal'] ?? [];
        $lines = fn ($t) => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $t)), fn ($l) => $l !== ''));
        $bullets = [];
        $entries = 0;
        $dated = 0;
        foreach (['experience', 'projects'] as $k) {
            foreach ($d[$k] ?? [] as $r) {
                $b = $lines($r['bullets'] ?? $r['details'] ?? '');
                if (! $b && empty($r['role']) && empty($r['name'])) {
                    continue;
                }
                $entries++;
                $dated += (! empty($r['start']) || ! empty($r['end']) || ! empty($r['date']) || ! empty($r['current'])) ? 1 : 0;
                foreach ($b as $x) {
                    $bullets[] = preg_replace('/^\s*[•*\-–]\s*/u', '', $x);
                }
            }
        }
        $nb = count($bullets);
        $verbs = count(array_filter($bullets, fn ($b) => preg_match(self::ACTION, $b)));
        $nums = count(array_filter($bullets, fn ($b) => preg_match('/\d/', $b)));
        $skills = array_values(array_filter(array_map('trim', preg_split('/[,\n;|•]+/u', (string) ($d['skills'] ?? '')))));
        $sumWords = str_word_count(strip_tags((string) ($d['summary'] ?? '')));
        $text = AtsEngine::resumeText($d);
        $words = str_word_count($text);
        $contact = (! empty($p['linkedin']) || ! empty($p['portfolio']) || ! empty($p['city']));
        $perEntry = $entries ? $nb / $entries : 0;

        $c = [];
        $add = function (string $key, string $label, int $max, float $ratio, string $tip) use (&$c) {
            $got = (int) round($max * max(0, min(1, $ratio)));
            $c[] = ['key' => $key, 'label' => $label, 'got' => $got, 'max' => $max, 'tip' => $got >= $max * 0.999 ? '' : $tip];
        };
        $add('name', 'Name', 6, ! empty(trim((string) ($p['name'] ?? ''))) ? 1 : 0, 'Add your full name at the top.');
        $add('email', 'Email', 6, filter_var($p['email'] ?? '', FILTER_VALIDATE_EMAIL) ? 1 : 0, 'Add a working email address.');
        $add('phone', 'Phone', 6, preg_match('/\d{8,}/', preg_replace('/\D/', '', (string) ($p['phone'] ?? ''))) ? 1 : 0, 'Add a phone number recruiters can call.');
        $add('contact', 'City or profile link', 3, $contact ? 1 : 0, 'Add your city or a LinkedIn link.');
        $add('summary', 'Professional summary', 10, $sumWords >= 25 ? 1 : $sumWords / 25, 'Write a summary of 25 to 80 words about your strengths and goal.');
        $add('experience', 'Experience or projects with dates', 14, $entries ? (0.6 + 0.4 * ($dated / $entries)) : 0, 'Add your jobs, internships or projects with dates.');
        $add('bullets', 'Achievement bullets', 8, min(1, $perEntry / 2.5), 'Write 2 to 4 short bullet points under each job or project.');
        $add('verbs', 'Starts with action verbs', 8, $nb ? min(1, ($verbs / $nb) / 0.6) : 0, 'Start bullets with action verbs such as Built, Led, Reduced.');
        $add('numbers', 'Numbers and results', 8, $nb ? min(1, ($nums / $nb) / 0.3) : 0, 'Add numbers to some bullets: percentages, team size, volume, savings.');
        $add('education', 'Education', 8, ! empty($d['education']) ? 1 : 0, 'Add your education.');
        $add('skills', 'Skills list', 12, min(1, count($skills) / 8), 'List at least 8 skills, tools and methods that match your target roles.');
        $add('structure', 'Standard headings and plain text', 5, 1, '');
        $add('length', 'Length', 6, $words >= 250 && $words <= 900 ? 1 : ($words < 250 ? $words / 250 : max(0, 1 - ($words - 900) / 600)), $words < 250 ? 'Add more detail: aim for 250 to 900 words.' : 'Shorten it: keep it under about 900 words.');

        $score = (int) min(100, array_sum(array_column($c, 'got')));
        $tips = array_values(array_filter(array_map(fn ($x) => $x['tip'], $c)));

        return ['score' => $score, 'checks' => $c, 'tips' => array_slice($tips, 0, 5), 'level' => $score >= 85 ? 'excellent' : ($score >= 70 ? 'good' : ($score >= 50 ? 'fair' : 'low'))];
    }
}
