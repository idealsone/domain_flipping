<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Turns the text of an uploaded resume (PDF, Word or plain text, already extracted) into the builder's structured data. */
class ResumeImport
{
    private const HEADINGS = [
        'summary' => 'professional summary|career summary|summary|profile|professional profile|objective|career objective|about me|about',
        'experience' => 'work experience|professional experience|employment history|work history|experience|employment|internships?|career history',
        'education' => 'education(?:al)?(?: (?:& |and )?(?:qualifications?|training))?|academic (?:background|qualifications?)|qualifications?',
        'skills' => 'technical skills|key skills|core (?:skills|competencies)|skills(?: (?:& |and )?(?:tools|expertise|competencies))?|competencies|areas of expertise|tools(?: (?:& |and )?technologies)?',
        'projects' => 'personal projects|academic projects|key projects|projects?',
        'certifications' => 'certifications?(?: (?:& |and )?(?:licen[sc]es|courses))?|licen[sc]es|courses|training|professional development',
        'languages' => 'languages?(?: known)?',
        'awards' => 'awards?(?: (?:& |and )?(?:honou?rs|achievements))?|achievements|honou?rs|accomplishments|volunteering|extra.?curricular(?: activities)?|activities',
    ];

    private const MONTH = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?';

    public static function blank(string $template): array
    {
        return ['version' => 1, 'title' => 'Imported resume', 'template' => $template, 'accent' => '#2258d6', 'font' => 'Arial', 'photo' => '', 'showPhoto' => true, 'photoShape' => 'round', 'documentLanguage' => 'en', 'sectionLabels' => [], 'spacing' => 'comfortable',
            'personal' => ['name' => '', 'role' => '', 'email' => '', 'phone' => '', 'city' => '', 'linkedin' => '', 'portfolio' => ''],
            'summary' => '', 'skills' => '', 'languages' => '', 'awards' => '', 'experience' => [], 'education' => [], 'projects' => [], 'certifications' => [],
            'order' => ['summary', 'experience', 'education', 'projects', 'skills', 'certifications', 'languages', 'awards'], 'hidden' => [], 'example' => false];
    }

    /** @return array{data:array,found:array<string,int>,warnings:list<string>} */
    public static function parse(string $text, string $template, string $fileName = ''): array
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{2022}", "\u{25AA}", "\u{25CF}", "\u{2023}", "\u{F0B7}", "\u{F0A7}", "\u{2043}"], ["\n", "\n", ' ', '•', '•', '•', '•', '•', '•', '•'], $text);
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;
        $lines = array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), explode("\n", $text)), fn ($l) => $l !== ''));
        $d = self::blank($template);
        $warnings = [];

        // Split into sections by heading lines.
        $head = [];
        $sections = [];
        $cur = null;
        foreach ($lines as $l) {
            $key = self::heading($l);
            if ($key) {
                $cur = $key;
                $sections[$cur] ??= [];

                continue;
            }
            if ($cur === null) {
                $head[] = $l;
            } else {
                $sections[$cur][] = $l;
            }
        }

        // Header: name, role, contact details.
        $all = implode(' ', array_slice($lines, 0, 14));
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $all, $m)) {
            $d['personal']['email'] = mb_substr($m[0], 0, 120);
        }
        if (preg_match('/(?<![\d])(\+?\d[\d\s().\-]{8,16}\d)(?![\d])/', $all, $m)) {
            $d['personal']['phone'] = mb_substr(trim($m[1]), 0, 30);
        }
        if (preg_match('#(?:https?://)?(?:[a-z]{2,3}\.)?linkedin\.com/[^\s|,;]+#i', $all, $m)) {
            $d['personal']['linkedin'] = mb_substr($m[0], 0, 200);
        }
        if (preg_match('#(?:https?://)?(?:www\.)?(?:github\.com|behance\.net|dribbble\.com|[a-z0-9\-]+\.(?:dev|io|me))/[^\s|,;]*#i', $all, $m) || preg_match('#https?://(?!.*linkedin)[^\s|,;]+#i', $all, $m)) {
            $d['personal']['portfolio'] = mb_substr($m[0], 0, 200);
        }
        $nameLine = '';
        $headLines = $head ?: array_slice($lines, 0, 5);
        foreach ($headLines as $i => $l) {
            if (self::isContact($l) || str_word_count($l) > 5 || preg_match('/resume|curriculum|vitae|\d/i', $l)) {
                continue;
            }
            $nameLine = $l;
            if (preg_match('/^[\p{L}][\p{L} .\'\-]{1,60}$/u', $l)) {
                $d['personal']['name'] = Str::title(mb_strtolower($l) === $l || mb_strtoupper($l) === $l ? mb_strtolower($l) : $l);
                // The line after the name is usually the job title.
                $next = $headLines[$i + 1] ?? '';
                if ($next !== '' && ! self::isContact($next) && str_word_count($next) <= 8 && ! preg_match('/\d/', $next)) {
                    $d['personal']['role'] = mb_substr($next, 0, 120);
                }
            }
            break;
        }
        foreach ($headLines as $l) {
            foreach (preg_split('/\s*[|•·]\s*|\s{2,}|\s—\s/u', $l) as $part) {
                if ($d['personal']['city'] === '' && preg_match('/^[\p{L} .\']{2,30},\s*[\p{L} .]{2,30}$/u', trim($part)) && ! self::isContact($part) && trim($part) !== $nameLine) {
                    $d['personal']['city'] = mb_substr(trim($part), 0, 80);
                }
            }
        }
        if ($d['personal']['name'] === '') {
            $warnings[] = 'We could not tell your name from the file. Please type it in.';
        }

        $found = [];
        // Summary and plain-text sections.
        $d['summary'] = mb_substr(implode(' ', $sections['summary'] ?? []), 0, 3000);
        $d['skills'] = mb_substr(self::skills($sections['skills'] ?? []), 0, 4000);
        $d['languages'] = mb_substr(implode(', ', array_map(fn ($l) => trim($l, " •-"), $sections['languages'] ?? [])), 0, 600);
        $d['awards'] = mb_substr(implode("\n", array_map(fn ($l) => trim($l, " •-"), $sections['awards'] ?? [])), 0, 3000);
        $d['experience'] = self::entries($sections['experience'] ?? [], 'experience');
        $d['education'] = self::entries($sections['education'] ?? [], 'education');
        $d['projects'] = self::entries($sections['projects'] ?? [], 'projects');
        foreach (array_slice($sections['certifications'] ?? [], 0, 20) as $l) {
            $l = trim($l, " •-");
            if ($l === '') {
                continue;
            }
            $year = preg_match('/\b(19|20)\d{2}\b/', $l, $y) ? $y[0] : '';
            $name = trim(preg_replace('/\s*[,|–—-]?\s*\(?\b(19|20)\d{2}\b\)?/u', '', $l));
            [$n, $issuer] = array_pad(preg_split('/\s+[-–—|]\s+|\s+by\s+|,\s+/u', $name, 2), 2, '');
            $d['certifications'][] = ['id' => (string) Str::uuid(), 'name' => mb_substr($n, 0, 200), 'issuer' => mb_substr($issuer, 0, 200), 'date' => $year];
        }

        // Nothing recognisable: keep the text, so nothing is lost.
        if (! $sections) {
            $warnings[] = 'We could not find section headings such as Experience or Education, so your text was placed in the summary. Move it into the right sections.';
            $d['summary'] = mb_substr(implode("\n", array_slice($lines, 0, 80)), 0, 3000);
        } else {
            foreach (['experience' => 'work experience', 'education' => 'education', 'skills' => 'skills'] as $k => $label) {
                if (empty($d[$k])) {
                    $warnings[] = "We did not find a {$label} section. Add it in the builder.";
                }
            }
        }
        foreach (['experience', 'education', 'projects', 'certifications'] as $k) {
            $found[$k] = count($d[$k]);
        }
        $found['skills'] = count(array_filter(array_map('trim', explode(',', $d['skills']))));
        $d['title'] = $d['personal']['name'] !== '' ? $d['personal']['name'].' · Imported' : ($fileName !== '' ? mb_substr(preg_replace('/\.[a-z0-9]+$/i', '', $fileName), 0, 120) : 'Imported resume');
        $d['imported'] = true;

        return ['data' => $d, 'found' => $found, 'warnings' => $warnings];
    }

    private static function heading(string $l): ?string
    {
        $t = trim(preg_replace('/[:\-–—_=*#]+$/u', '', trim($l, " \t*#=_-–—")));
        if ($t === '' || mb_strlen($t) > 48 || str_word_count($t) > 6) {
            return null;
        }
        foreach (self::HEADINGS as $k => $re) {
            if (preg_match('/^(?:'.$re.')$/iu', $t)) {
                return $k;
            }
        }

        return null;
    }

    private static function isContact(string $l): bool
    {
        return (bool) preg_match('/@|https?:|www\.|linkedin|github|\+?\d[\d\s().\-]{8,}/i', $l);
    }

    private static function skills(array $lines): string
    {
        $items = [];
        foreach ($lines as $l) {
            $l = preg_replace('/^[A-Za-z &\/]{2,30}:\s*/u', '', trim($l, " •-"));
            foreach (preg_split('/[,;|•·]+|\s{2,}/u', $l) as $s) {
                $s = trim($s, " .•-");
                if ($s !== '' && mb_strlen($s) <= 60) {
                    $items[mb_strtolower($s)] = $s;
                }
            }
        }

        return implode(', ', array_slice(array_values($items), 0, 60));
    }

    private static function dateRange(string $l): ?array
    {
        $m = self::MONTH;
        $d = "(?:{$m}\\s*)?(?:19|20)\\d{2}|\\d{1,2}\\/(?:19|20)\\d{2}";
        if (preg_match("/({$d})\\s*(?:-|–|—|to|until)\\s*({$d}|present|current|now|ongoing|till date)/iu", $l, $x, PREG_OFFSET_CAPTURE)) {
            return [trim($x[1][0]), trim($x[2][0]), $x[0][1], strlen($x[0][0])];
        }
        if (preg_match("/\\b({$m}\\s*(?:19|20)\\d{2}|(?:19|20)\\d{2})\\b/iu", $l, $x, PREG_OFFSET_CAPTURE) && str_word_count($l) <= 8) {
            return [trim($x[1][0]), '', $x[0][1], strlen($x[0][0])];
        }

        return null;
    }

    private static function entries(array $lines, string $kind): array
    {
        $out = [];
        $cur = null;
        $flush = function () use (&$cur, &$out, $kind) {
            if ($cur) {
                $b = array_slice($cur['_b'], 0, 12);
                unset($cur['_b'], $cur['_pl']);
                if ($kind === 'experience' && $cur['city'] === '' && str_contains($cur['company'], ', ')) {
                    [$cur['company'], $cur['city']] = array_map(fn ($x) => mb_substr(trim($x), 0, 120), explode(', ', $cur['company'], 2));
                }
                $cur[$kind === 'education' ? 'details' : ($kind === 'projects' ? 'details' : 'bullets')] = mb_substr(implode("\n", $b), 0, 6000);
                $out[] = $cur;
            }
            $cur = null;
        };
        foreach ($lines as $l) {
            $isBullet = (bool) preg_match('/^\s*[•*\-–▪◦]\s*/u', $l);
            $clean = trim(preg_replace('/^\s*[•*\-–▪◦]\s*/u', '', $l));
            $range = $isBullet ? null : self::dateRange($l);
            $isTitle = ! $isBullet && ($range || ($kind === 'projects' && str_word_count($clean) <= 9 && ! preg_match('/[.]$/', $clean) && (! $cur || count($cur['_b']) > 0)) || ($kind === 'education' && preg_match('/\b(bachelor|master|b\.?\s?tech|m\.?\s?tech|b\.?sc|m\.?sc|b\.?com|m\.?com|bba|mba|diploma|ph\.?d|b\.?e\b|12th|10th|higher secondary|secondary|school|university|college|institute)\b/iu', $clean) && str_word_count($clean) <= 14));
            if ($isTitle && $kind !== 'projects' && $range === null && $cur && ! $cur['_b'] && $kind === 'education') {
                // A second line of the same education entry (school after degree).
                $cur['school'] = $cur['school'] ?: mb_substr($clean, 0, 200);

                continue;
            }
            if ($isTitle) {
                $title = $clean;
                if ($range) {
                    $title = trim(substr($clean, 0, $range[2]).' '.substr($clean, $range[2] + $range[3]), " \t|,;-–—()");
                }
                $split = fn ($x) => array_values(array_filter(array_map(fn ($y) => trim($y, " ,;()"), preg_split('/\s+[|–—]\s+|\s+-\s+|\s+at\s+|\s*\|\s*|,\s+(?=[A-Z])/u', $x)), fn ($y) => $y !== ''));
                $parts = $split($title);
                $current = $range && in_array(mb_strtolower($range[1]), ['present', 'current', 'now', 'ongoing', 'till date'], true);
                // A dated line right after an undated title line belongs to that same entry.
                if ($range && $cur && ! $cur['_b'] && $cur['start'] === '' && $kind !== 'projects') {
                    $cur['start'] = $range[0];
                    $cur['end'] = $current ? '' : $range[1];
                    if ($kind === 'experience') {
                        $cur['current'] = $current;
                        $cur['company'] = $cur['company'] ?: mb_substr($parts[0] ?? '', 0, 180);
                    } else {
                        $cur['school'] = $cur['school'] ?: mb_substr($parts[0] ?? '', 0, 200);
                    }

                    continue;
                }
                // "Role" on its own line (swallowed as text of the previous entry), then "Company | dates".
                $borrowed = '';
                if ($kind === 'experience' && $range && $cur && ! empty($cur['_pl']) && $cur['_b'] && count($parts) === 1) {
                    $borrowed = array_pop($cur['_b']);
                }
                $flush();
                $id = (string) Str::uuid();
                if ($kind === 'experience') {
                    $cur = $borrowed !== ''
                        ? ['id' => $id, 'role' => mb_substr($borrowed, 0, 180), 'company' => mb_substr($parts[0] ?? '', 0, 180), 'city' => '', 'type' => '', 'start' => $range[0] ?? '', 'end' => $current ? '' : ($range[1] ?? ''), 'current' => $current, '_b' => []]
                        : ['id' => $id, 'role' => mb_substr($parts[0] ?? '', 0, 180), 'company' => mb_substr($parts[1] ?? '', 0, 180), 'city' => mb_substr($parts[2] ?? '', 0, 80), 'type' => '', 'start' => $range[0] ?? '', 'end' => $current ? '' : ($range[1] ?? ''), 'current' => $current, '_b' => []];
                } elseif ($kind === 'education') {
                    $cur = ['id' => $id, 'degree' => mb_substr($parts[0] ?? '', 0, 200), 'school' => mb_substr($parts[1] ?? '', 0, 200), 'start' => $range[0] ?? '', 'end' => $current ? '' : ($range[1] ?? ''), 'city' => '', 'grade' => '', '_b' => []];
                } else {
                    $cur = ['id' => $id, 'name' => mb_substr($parts[0] ?? $clean, 0, 200), 'role' => '', 'tools' => mb_substr($parts[1] ?? '', 0, 300), 'link' => '', '_b' => []];
                }
            } elseif ($cur) {
                $cur['_b'][] = $clean;
                $cur['_pl'] = ! $isBullet && str_word_count($clean) <= 7 && ! preg_match('/[.;:]$/', $clean);
            } else {
                // Content before the first dated line (role/company line without a date): start an entry from it.
                if (! $isBullet && $kind === 'experience') {
                    $parts = array_values(array_filter(array_map('trim', preg_split('/\s+[|–—]\s+|\s+-\s+|\s+at\s+|\s*\|\s*/u', $clean))));
                    $cur = ['id' => (string) Str::uuid(), 'role' => mb_substr($parts[0] ?? '', 0, 180), 'company' => mb_substr($parts[1] ?? '', 0, 180), 'city' => '', 'type' => '', 'start' => '', 'end' => '', 'current' => false, '_b' => []];
                }
            }
        }
        $flush();

        return array_slice($out, 0, 20);
    }
}
