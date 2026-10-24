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
        'contact' => 'contact(?: (?:details|information|info|me))?|personal (?:details|information|info)',
    ];

    private const ROLE = '/\\b(manager|engineer|analyst|lead|associate|executive|developer|consultant|intern|officer|head|director|specialist|coordinator|designer|administrator|architect|accountant|assistant|supervisor|trainee|representative|advis[eo]r|scientist|programmer|teacher|tester|founder|agent|operator|technician|clerk|recruiter|writer|editor|sme|vp)\\b/i';

    private const COMPANY = '/\\b(pvt|private|ltd|limited|inc|llp|llc|corp|corporation|company|technologies|tech|solutions|services|systems|labs|bank|school|university|college|institute|group|software|consulting|infotech|industries|enterprises|foundation|hospital)\\b\\.?/i';

    private const MONTH = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?';

    public static function blank(string $template): array
    {
        return ['version' => 1, 'title' => 'Imported resume', 'template' => $template, 'accent' => '#2258d6', 'font' => 'Arial', 'photo' => '', 'showPhoto' => true, 'photoShape' => 'round', 'documentLanguage' => 'en', 'sectionLabels' => [], 'spacing' => 'comfortable',
            'personal' => ['name' => '', 'role' => '', 'email' => '', 'phone' => '', 'city' => '', 'linkedin' => '', 'portfolio' => ''],
            'summary' => '', 'skills' => '', 'languages' => '', 'awards' => '', 'experience' => [], 'education' => [], 'projects' => [], 'certifications' => [],
            'order' => ['summary', 'experience', 'education', 'projects', 'skills', 'certifications', 'languages', 'awards'], 'hidden' => [], 'example' => false];
    }

    /** @return array{data:array,found:array<string,int>,warnings:list<string>} */
    public static function parse(string $text, string $template, string $fileName = '', bool $paragraphs = false): array
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}", "\u{2022}", "\u{25AA}", "\u{25CF}", "\u{2023}", "\u{F0B7}", "\u{F0A7}", "\u{2043}"], ["\n", "\n", ' ', '•', '•', '•', '•', '•', '•', '•'], $text);
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text) ?? $text;
        // With $paragraphs (text from the PDF reader, which adds a blank line where the gap between lines is wider than usual), a blank line marks a paragraph break.
        $lines = [];
        $newPara = [];
        $gap = true;
        foreach (explode("\n", $text) as $l) {
            $l = self::cleanLine($l);
            if ($l === '') {
                $gap = true;

                continue;
            }
            $lines[] = $l;
            $newPara[] = $gap;
            $gap = false;
        }
        $hasParas = $paragraphs && count(array_filter($newPara)) > 3;
        $d = self::blank($template);
        $warnings = [];

        // Split into sections by heading lines. A heading can share its row with a date ("EDUCATION | 2004 - 2007").
        $head = [];
        $sections = [];
        $paras = [];
        $cur = null;
        foreach ($lines as $i => $l) {
            $key = self::heading($l);
            $rest = '';
            if (! $key && str_contains($l, ' | ')) {
                [$first, $rest] = explode(' | ', $l, 2);
                $key = self::heading($first);
            }
            if ($key) {
                $cur = $key;
                $sections[$cur] ??= [];
                if ($rest !== '') {
                    $sections[$cur][] = $rest;
                    $paras[$cur][] = true;
                }

                continue;
            }
            if ($cur === null) {
                $head[] = $l;
            } else {
                $sections[$cur][] = $l;
                $paras[$cur][] = $newPara[$i];
            }
        }
        // Lines of one wrapped sentence or bullet become one line again.
        foreach (['summary', 'experience', 'projects', 'awards'] as $k) {
            if (isset($sections[$k])) {
                $sections[$k] = self::joinWrapped($sections[$k], $paras[$k] ?? [], $hasParas);
            }
        }

        // Header: name, role, contact details. Two-column resumes often keep contact details in a side column further down.
        $near = implode(' ', array_merge(array_slice($lines, 0, 14), $sections['contact'] ?? []));
        $all = implode(' ', $lines);
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $near, $m) || preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $all, $m)) {
            $d['personal']['email'] = mb_substr($m[0], 0, 120);
        }
        if (preg_match('/(?<![\d,.])(\+?\d[\d\s().\-]{8,16}\d)(?![\d,.%])/', $near, $m) || preg_match('/(?:phone|mobile|mob|tel|contact)\.?\s*(?:no\.?)?\s*[:\-]?\s*(\+?\d[\d\s().\-]{8,16}\d)/i', $all, $m)) {
            $d['personal']['phone'] = mb_substr(trim($m[1]), 0, 30);
        }
        $all = $near;
        if (preg_match('#(?:https?://)?(?:[a-z]{2,3}\.)?linkedin\.com/[^\s|,;]+#i', $all, $m)) {
            $d['personal']['linkedin'] = mb_substr($m[0], 0, 200);
        }
        if (preg_match('#(?:https?://)?(?:www\.)?(?:github\.com|behance\.net|dribbble\.com|[a-z0-9\-]+\.(?:dev|io|me))/[^\s|,;]*#i', $all, $m) || preg_match('#https?://(?!.*linkedin)[^\s|,;]+#i', $all, $m)) {
            $d['personal']['portfolio'] = mb_substr($m[0], 0, 200);
        }
        $nameLine = '';
        $headLines = $head;
        if (! $headLines) {
            // The file starts with a heading (often a side column read first): look for a name followed by a job title anywhere.
            foreach ($lines as $i => $l) {
                $next = $lines[$i + 1] ?? '';
                if (preg_match('/^[\p{L}][\p{L} .\'\-]{1,60}$/u', $l) && str_word_count($l) >= 2 && str_word_count($l) <= 4 && ! self::heading($l) && preg_match(self::ROLE, $next) && str_word_count($next) <= 8 && ! self::heading($next)) {
                    $headLines = [$l, $next];
                    break;
                }
            }
            $headLines = $headLines ?: array_values(array_filter(array_slice($lines, 0, 5), fn ($l) => ! self::heading($l)));
        }
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
        foreach (array_merge($headLines, $sections['contact'] ?? []) as $l) {
            foreach (preg_split('/\s*[|•·]\s*|\s{2,}|\s—\s/u', $l) as $part) {
                if ($d['personal']['city'] === '' && preg_match('/^[\p{L} .\']{2,30},\s*[\p{L} .]{2,30}$/u', trim($part)) && ! self::isContact($part) && trim($part) !== $nameLine && trim($part) !== $d['personal']['role'] && ! preg_match(self::ROLE, $part)) {
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
        $pending = '';
        foreach (array_slice($sections['certifications'] ?? [], 0, 30) as $l) {
            $l = trim($l, " •-|");
            if ($l === '') {
                continue;
            }
            // A date on a line of its own (a right-hand date column) belongs to the certificate next to it.
            if (preg_match('/^(?:'.self::MONTH.'\s*)?(?:19|20)\d{2}$/iu', $l)) {
                $last = count($d['certifications']) - 1;
                if ($last >= 0 && $d['certifications'][$last]['date'] === '') {
                    $d['certifications'][$last]['date'] = self::monthCase($l);
                } else {
                    $pending = self::monthCase($l);
                }

                continue;
            }
            $year = preg_match('/(?:'.self::MONTH.'\s*)?\b(19|20)\d{2}\b/iu', $l, $y) ? self::monthCase($y[0]) : $pending;
            $pending = '';
            $name = trim(preg_replace('/\s*[,|–—-]?\s*\(?(?:'.self::MONTH.'\s*)?\b(19|20)\d{2}\b\)?/iu', '', $l), " |");
            [$n, $issuer] = array_pad(preg_split('/\s+[-–—|]\s+|\s+by\s+|,\s+/u', $name, 2), 2, '');
            $d['certifications'][] = ['id' => (string) Str::uuid(), 'name' => mb_substr($n, 0, 200), 'issuer' => mb_substr($issuer, 0, 200), 'date' => $year];
            if (count($d['certifications']) >= 20) {
                break;
            }
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
        // Letter-spaced headings ("W O R K E X P E R I E N C E") lose the space between words, so compare without spaces too.
        $bare = str_replace(' ', '', $t);
        foreach (self::HEADINGS as $k => $re) {
            if (preg_match('/^(?:'.$re.')$/iu', $t) || preg_match('/^(?:'.str_replace(' ', '', $re).')$/iu', $bare)) {
                return $k;
            }
        }

        return null;
    }

    /** One line of extracted text: letter-spaced words closed up, wide gaps (columns) kept as " | ", other spacing single. */
    private static function cleanLine(string $l): string
    {
        $parts = [];
        $l = preg_replace('/^\s*([•*▪◦\-–])\s+/u', '$1 ', $l);
        foreach (preg_split('/\t+|[ ]{3,}/u', trim($l)) as $seg) {
            $seg = trim($seg);
            $tokens = preg_split('/\s+/u', $seg);
            if (count($tokens) >= 4 && max(array_map('mb_strlen', $tokens)) === 1) {
                $seg = str_replace(["\x00", ' '], [' ', ''], preg_replace('/\s{2,}/u', "\x00", $seg));
            }
            if ($seg !== '') {
                $parts[] = preg_replace('/\s+/u', ' ', $seg);
            }
        }

        return implode(' | ', $parts);
    }

    /** Joins the lines of a wrapped sentence. With paragraph breaks from the PDF reader, every line inside a paragraph is a continuation; otherwise only a line that starts in lower case is. */
    private static function joinWrapped(array $lines, array $newPara, bool $hasParas): array
    {
        $out = [];
        foreach ($lines as $i => $l) {
            $prev = $out ? $out[count($out) - 1] : null;
            $bullet = (bool) preg_match('/^\s*[•*▪◦]\s*/u', $l);
            $dated = (bool) self::dateRange($l);
            $join = $prev !== null && ! $bullet && ! $dated && ! self::dateRange($prev) && (
                substr_count($prev, '(') > substr_count($prev, ')')
                || ($hasParas ? ! ($newPara[$i] ?? true) && mb_strlen($prev) >= 20 && ! preg_match('/^[A-Z0-9 &.,\/\-–|]+$/u', $prev) && ! (preg_match('/[.!?]$/u', $prev) && preg_match('/^\p{Lu}/u', $l))
                    : (preg_match('/^\p{Ll}/u', $l) && ! preg_match('/[.!?:]$/u', $prev)) || preg_match('/(?:[&,]|\b(?:and|of|to|the|with|for|in))$/u', $prev))
            );
            if ($join) {
                $out[count($out) - 1] = preg_match('/\p{L}-$/u', $prev) ? $prev.$l : $prev.' '.$l;
            } else {
                $out[] = $l;
            }
        }

        return $out;
    }

    /** Trims separators from a split part, and brackets only when they are unbalanced ("Masai School (Nolan Edutech Pvt Ltd)" keeps its bracket). */
    private static function trimPart(string $y): string
    {
        $y = trim($y, " ,;|");
        if (substr_count($y, '(') > substr_count($y, ')')) {
            $y = str_starts_with($y, '(') ? ltrim($y, '(') : $y.')';
        } elseif (substr_count($y, ')') > substr_count($y, '(')) {
            $y = rtrim($y, ')');
        }

        return trim($y);
    }

    /** "MBA, Innovation & Entrepreneurship, SIBM Pune, 2018 (7.60/10 CGPA)" on one line: degree, school, year and grade. */
    private static function eduLine(string $l, bool $isBullet): ?array
    {
        if (! preg_match('/\b(bachelor|master|b\.?\s?tech|m\.?\s?tech|b\.?sc|m\.?sc|b\.?com|m\.?com|b\.?a\b|m\.?a\b|bba|mba|pgdm|diploma|ph\.?d|b\.?e\b|12th|10th|hsc|ssc|higher secondary)/iu', $l)
            || substr_count($l, ',') < ($isBullet ? 1 : 2) || ! preg_match('/\b(?:19|20)\d{2}\b/', $l)) {
            return null;
        }
        $grade = '';
        $l = rtrim($l, ' .');
        if (preg_match('/\(([^()]*(?:c?gpa|%|percent|grade|marks|class)[^()]*)\)/iu', $l, $g)) {
            $grade = trim($g[1]);
            $l = str_replace($g[0], '', $l);
        }
        $range = self::dateRange($l);
        $start = $range && $range[1] !== '' ? $range[0] : '';
        $end = $range ? ($range[1] !== '' ? $range[1] : $range[0]) : '';
        if ($range) {
            $l = substr($l, 0, $range[2]).substr($l, $range[2] + $range[3]);
        }
        $parts = array_values(array_filter(array_map(fn ($x) => trim($x, " ,;|–—-"), explode(',', $l)), fn ($x) => $x !== ''));
        if (count($parts) < 2) {
            return null;
        }
        [$degree, $school] = count($parts) >= 3 ? [$parts[0].', '.$parts[1], implode(', ', array_slice($parts, 2))] : [$parts[0], $parts[1]];
        if (count($parts) >= 3 && preg_match('/\b(university|college|institute|school|academy|polytechnic|iit|nit|iim)\b/iu', $parts[1]) && ! preg_match('/\b(university|college|institute|school|academy|polytechnic|iit|nit|iim)\b/iu', $parts[2])) {
            [$degree, $school] = [$parts[0], $parts[1].', '.implode(', ', array_slice($parts, 2))];
        }

        return ['id' => (string) Str::uuid(), 'degree' => mb_substr($degree, 0, 200), 'school' => mb_substr($school, 0, 200), 'start' => self::monthCase($start), 'end' => self::monthCase($end), 'city' => '', 'grade' => mb_substr($grade, 0, 60), '_b' => []];
    }

    private static function monthCase(string $s): string
    {
        return preg_replace_callback('/\b\p{L}+\b/u', fn ($m) => preg_match('/^'.self::MONTH.'$/iu', $m[0]) || in_array(mb_strtolower($m[0]), ['present', 'current', 'till', 'date', 'now'], true) ? Str::ucfirst(mb_strtolower($m[0])) : $m[0], trim($s));
    }

    /** "APNATIME TECH PRIVATE LIMITED" reads better as "Apnatime Tech Private Limited"; short acronyms stay as they are. */
    private static function caps(string $s): string
    {
        return preg_match('/\p{Lu}{2,}.*\s.*\p{Lu}{2,}/u', $s) && mb_strtoupper($s) === $s ? preg_replace_callback('/\b(\p{Lu})(\p{Lu}{3,})\b/u', fn ($m) => $m[1].mb_strtolower($m[2]), $s) : $s;
    }

    private static function isContact(string $l): bool
    {
        return (bool) preg_match('/@|https?:|www\.|linkedin|github|\+?\d[\d\s().\-]{8,}/i', $l);
    }

    private static function skills(array $lines): string
    {
        $items = [];
        // A skill that wrapped onto the next line ("Strategic Planning &" / "Execution").
        $joined = [];
        foreach ($lines as $l) {
            $prev = $joined ? $joined[count($joined) - 1] : null;
            $listy = fn ($x) => preg_match_all('/\s[•·|;]\s|,\s/u', $x);
            if ($prev !== null && (preg_match('/[&(\/]$|\p{L}-$/u', $prev) || substr_count($prev, '(') > substr_count($prev, ')') || ($listy($prev) >= 2 && $listy($l) >= 1 && ! preg_match('/[•·|;,]$/u', $prev)))) {
                $joined[count($joined) - 1] = preg_match('/\p{L}-$/u', $prev) ? $prev.$l : $prev.' '.$l;
            } else {
                $joined[] = $l;
            }
        }
        foreach ($joined as $l) {
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
                if ($kind === 'experience') {
                    $first = $cur['_b'][0] ?? '';
                    $firstBare = trim(preg_replace('/\s*\(.*$/u', '', $first));
                    if (empty($cur['_sub']) && $cur['company'] === '' && $firstBare !== '' && str_word_count($firstBare) <= 7 && ! preg_match('/[.;:]$/u', $firstBare)
                        && (preg_match(self::COMPANY, $cur['role']) || (preg_match(self::ROLE, $firstBare) && ! preg_match(self::ROLE, $cur['role'])))) {
                        // "Company | dates" with the job title on the next line.
                        $cur['company'] = $cur['role'];
                        $cur['role'] = mb_substr($firstBare, 0, 180);
                        if ($first !== $firstBare && preg_match('/\((.+)$/u', $first, $pm)) {
                            $cur['_b'][0] = trim($pm[1], ' ()');
                        } else {
                            array_shift($cur['_b']);
                        }
                    } elseif ($cur['company'] !== '' && ((preg_match(self::COMPANY, $cur['role']) && ! preg_match(self::COMPANY, $cur['company'])) || (preg_match(self::ROLE, $cur['company']) && ! preg_match(self::ROLE, $cur['role'])))) {
                        // "Company, Job title" written the other way round.
                        [$cur['role'], $cur['company']] = [$cur['company'], $cur['role']];
                    }
                    $cur['role'] = self::caps(preg_replace('/\s+\(?(?:current|present)\)?$/iu', '', $cur['role']));
                    $cur['company'] = self::caps($cur['company']);
                }
                if ($kind === 'education') {
                    foreach ($cur['_b'] as $bi => $line) {
                        if ($cur['grade'] === '' && preg_match('/^(?:c?gpa|percentage|grade|score|marks|result)\s*[:\-]?\s*(.{1,40})$/iu', $line, $gm)) {
                            $cur['grade'] = trim($gm[1]);
                            unset($cur['_b'][$bi]);
                        }
                    }
                    $cur['_b'] = array_values($cur['_b']);
                }
                if (isset($cur['start'])) {
                    $cur['start'] = self::monthCase($cur['start']);
                    $cur['end'] = self::monthCase($cur['end']);
                }
                $b = array_slice($cur['_b'], 0, 12);
                unset($cur['_b'], $cur['_pl'], $cur['_bul'], $cur['_sub']);
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
            // "Project name – what it did" on one line.
            if ($kind === 'projects' && ! $range && preg_match('/^(.{3,60}?)\s+[–—-]\s+(.{20,})$/u', $clean, $pm) && str_word_count($pm[1]) <= 6 && ! preg_match('/[.,;]/u', $pm[1])) {
                $flush();
                $cur = ['id' => (string) Str::uuid(), 'name' => mb_substr($pm[1], 0, 200), 'role' => '', 'tools' => '', 'link' => '', '_b' => [$pm[2]]];

                continue;
            }
            if ($kind === 'education' && ($edu = self::eduLine($clean, $isBullet))) {
                $flush();
                $cur = $edu;

                continue;
            }
            $isTitle = ! $isBullet && ($range || ($kind === 'projects' && str_word_count($clean) <= 9 && ! preg_match('/[.]$/', $clean) && (! $cur || count($cur['_b']) > 0)) || ($kind === 'education' && preg_match('/\b(bachelor|master|b\.?\s?tech|m\.?\s?tech|b\.?sc|m\.?sc|b\.?com|m\.?com|bba|mba|diploma|ph\.?d|b\.?e\b|12th|10th|higher secondary|secondary|school|polytechnic|academy|university|college|institute)\b/iu', $clean) && str_word_count($clean) <= 14));
            if ($isTitle && $kind !== 'projects' && $range === null && $cur && ! $cur['_b'] && $kind === 'education') {
                // A second line of the same education entry (school after degree), or the degree after a date row.
                if ($cur['degree'] === '') {
                    $cur['degree'] = mb_substr($clean, 0, 200);
                } else {
                    $cur['school'] = $cur['school'] ?: mb_substr($clean, 0, 200);
                }

                continue;
            }
            if ($isTitle) {
                $title = $clean;
                if ($range) {
                    $title = trim(substr($clean, 0, $range[2]).' '.substr($clean, $range[2] + $range[3]), " \t|,;-–—()");
                }
                $split = fn ($x) => array_values(array_filter(array_map(fn ($y) => self::trimPart($y), preg_split('/\s+[|–—]\s+|\s+-\s+|\s+at\s+|\s*\|\s*|,\s+(?=[A-Z])/u', $x)), fn ($y) => $y !== ''));
                $parts = $split($title);
                $current = $range && in_array(mb_strtolower($range[1]), ['present', 'current', 'now', 'ongoing', 'till date'], true);
                // A dated line right after an undated title line belongs to that same entry.
                if ($range && $cur && ! $cur['_b'] && $cur['start'] === '' && $kind !== 'projects') {
                    $cur['start'] = $range[0];
                    $cur['end'] = $current ? '' : $range[1];
                    if ($kind === 'experience') {
                        $cur['current'] = $current;
                        // A title that looked like a second job at the same employer, followed by its own "Company | dates" line.
                        $cur['company'] = ! empty($cur['_sub']) && ($parts[0] ?? '') !== '' ? mb_substr($parts[0], 0, 180) : ($cur['company'] ?: mb_substr($parts[0] ?? '', 0, 180));
                        $cur['city'] = ! empty($cur['_sub']) ? mb_substr($parts[1] ?? '', 0, 80) : $cur['city'];
                        unset($cur['_sub']);
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
            } elseif ($cur && $kind === 'experience' && ! $isBullet && ! empty($cur['_bul']) && str_word_count($clean) <= 8 && ! preg_match('/[.;:,]$|^\p{Ll}/u', $clean) && preg_match(self::ROLE, $clean)) {
                // Another job title under the same employer ("Director - Strategic Partnerships" after the bullets of the first title).
                $flush();
                $prev = $out[count($out) - 1];
                $cur = ['id' => (string) Str::uuid(), 'role' => mb_substr($clean, 0, 180), 'company' => $prev['company'], 'city' => $prev['city'], 'type' => '', 'start' => '', 'end' => '', 'current' => false, '_b' => [], '_sub' => true];
            } elseif ($cur) {
                $cur['_bul'] = ! empty($cur['_bul']) || $isBullet;
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
