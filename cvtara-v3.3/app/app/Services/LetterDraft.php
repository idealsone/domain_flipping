<?php

namespace App\Services;

/**
 * A complete first cover letter built only from the person's saved resume and, when given, an ATS Match report for the job.
 * Every claim comes from the resume: the evidence paragraphs reuse the person's own bullets that best fit the job.
 * The one thing the resume cannot know (why this employer) is left as a clearly marked [bracket] for the person to fill in.
 */
class LetterDraft
{
    private static function sentence(string $bullet): string
    {
        $b = trim((string) preg_replace('/^\s*[•*\-–●]\s*/u', '', $bullet));
        $b = rtrim($b, " \t.;,");
        if ($b === '') {
            return '';
        }
        // "Led a team of 8" reads as "I led a team of 8"; a line that already has a subject is kept as written
        if (! preg_match('/^(I|My|We|Our)\b/u', $b)) {
            $b = 'I '.mb_strtolower(mb_substr($b, 0, 1)).mb_substr($b, 1);
        }

        return $b.'.';
    }

    /** @return string[] */
    private static function lines(string $t): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $t)), fn ($l) => mb_strlen($l) > 15));
    }

    /** @param string[] $words lower-case job keywords */
    private static function fit(string $line, array $words): int
    {
        $l = mb_strtolower($line);
        $n = 0;
        foreach ($words as $w) {
            if ($w !== '' && str_contains($l, $w)) {
                $n += 2;
            }
        }

        return $n + (preg_match('/\d/', $line) ? 1 : 0);
    }

    private static function list(array $items): string
    {
        $items = array_values(array_filter($items));
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    /**
     * @param  array  $resume  saved resume data (personal, summary, experience, …)
     * @param  ?array  $report  ATS Match report for this job, or null
     * @return array{personal:array,role:string,company:string,subject:string,salutation:string,opening:string,evidence:string,motivation:string,closing:string,signoff:string}
     */
    public static function build(array $resume, string $role, string $company, ?array $report = null): array
    {
        $p = $resume['personal'] ?? [];
        $role = trim($role) ?: trim((string) ($p['role'] ?? '')) ?: 'advertised';
        $company = trim($company);
        $at = $company !== '' ? ' at '.$company : '';
        $exp = array_values(array_filter($resume['experience'] ?? [], fn ($x) => ! empty($x['role']) || ! empty($x['company']) || ! empty($x['bullets'])));
        $words = array_map('mb_strtolower', array_merge($report['keywords']['found'] ?? [], array_column(array_filter($report['result']['requirements'] ?? [], fn ($r) => $r['status'] !== 'not_found'), 'name')));
        $words = array_values(array_unique(array_filter(array_map(fn ($w) => trim((string) preg_replace('/[^\p{L}\p{N}&+# ]/u', ' ', $w)), $words), fn ($w) => mb_strlen($w) > 2)));

        // opening: the role, then years and current position as the resume states them
        preg_match('/([0-9]{1,2})\s*\+?\s*(?:years?|yrs?)/iu', AtsEngine::resumeText($resume), $y);
        $now = $exp[0] ?? null;
        $current = $now ? trim(implode(' at ', array_filter([trim((string) ($now['role'] ?? '')), trim((string) ($now['company'] ?? ''))]))) : '';
        $opening = 'I am writing to apply for the '.$role.' role'.$at.'.';
        if ($y || $current !== '') {
            $opening .= ' I bring '.($y ? $y[1].'+ years of experience' : 'hands-on experience').($current !== '' ? (! empty($now['current']) || empty($now['end']) ? ', most recently as '.$current : ', including my role as '.$current) : '').'.';
        }
        $summary = trim((string) ($resume['summary'] ?? ''));
        if ($summary !== '') {
            $first = preg_split('/(?<=[.!?])\s+/u', $summary)[0] ?? '';
            if (mb_strlen($first) >= 40 && mb_strlen($first) <= 260) {
                $opening .= ' '.(preg_match('/^(I|My)\b/u', $first) ? $first : 'In short: '.mb_strtolower(mb_substr($first, 0, 1)).mb_substr($first, 1));
                $opening = rtrim($opening, '.').'.';
            }
        }

        // evidence: the person's own bullets that best fit this job, one paragraph per job, at most two jobs
        $paras = [];
        foreach (array_slice($exp, 0, 4) as $x) {
            $b = self::lines((string) ($x['bullets'] ?? $x['details'] ?? ''));
            if (! $b) {
                continue;
            }
            usort($b, fn ($a, $c) => self::fit($c, $words) <=> self::fit($a, $words));
            $pick = array_map([self::class, 'sentence'], array_slice($b, 0, 2));
            $where = trim((string) ($x['company'] ?? ''));
            $as = trim((string) ($x['role'] ?? ''));
            $lead = $where !== '' ? 'At '.$where.($as !== '' ? ', as '.$as.',' : ',') : ($as !== '' ? 'As '.$as.',' : '');
            $text = implode(' ', array_filter($pick));
            if ($lead !== '') {
                $text = $lead.' '.mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
                $text = (string) preg_replace('/^(At [^,]+,(?: as [^,]+,)?|As [^,]+,) i /u', '$1 I ', $text);
            }
            $paras[] = $text;
            if (count($paras) >= 2) {
                break;
            }
        }
        if (! $paras && $summary !== '') {
            $paras[] = $summary;
        }

        // why this job: matched themes from the report, then one honest placeholder
        $themes = array_slice(array_values(array_filter($report['keywords']['found'] ?? [], fn ($k) => mb_strlen($k) > 3)), 0, 3);
        $motivation = ($themes ? 'This role’s focus on '.mb_strtolower(self::list($themes)).' matches the work I have done most. ' : '')
            .'What draws me to '.($company !== '' ? $company : 'your organisation').' is [add one specific reason you have checked: a programme, product, value or recent news].';

        $name = trim((string) ($p['name'] ?? ''));

        return [
            'personal' => ['name' => $name, 'email' => (string) ($p['email'] ?? ''), 'phone' => (string) ($p['phone'] ?? ''), 'city' => (string) ($p['city'] ?? '')],
            'role' => $role === 'advertised' ? '' : $role,
            'company' => $company,
            'subject' => 'Application for '.($role === 'advertised' ? 'the advertised role' : $role),
            'salutation' => 'Dear Hiring Manager,',
            'opening' => $opening,
            'evidence' => implode("\n\n", $paras),
            'motivation' => $motivation,
            'closing' => 'I would welcome the chance to discuss how my experience can help '.($company !== '' ? $company : 'your team').'. Thank you for your time and consideration.',
            'signoff' => 'Kind regards,',
        ];
    }
}
