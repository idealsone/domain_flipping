<?php

namespace App\Services;

/**
 * ATS Match review. Compares one resume with one job description and returns requirement-by-requirement evidence, document checks,
 * the job's keywords and a match estimate. It runs on the server so the result cannot be produced without a reserved scan.
 * Deterministic: no web lookups, no employer ATS access, no hiring prediction.
 */
class AtsEngine
{
    private const STOP = 'the a an and or of for to in on at by with as from be is are was were have has this that your our you we will must should required requirements experience knowledge skills ability strong excellent good work working team role candidate successful responsibilities responsibility';

    private const ACTION = '/\b(built|builds|developed|delivered|implemented|designed|created|led|leads|managed|manages|coordinated|screened|sourced|analysed|analyzed|improved|reduced|increased|maintained|automated|deployed|tested|prepared|supported|taught|mentored|conducted|launched|wrote|resolved|produced|reported|own|owned|owns|drove|drives|grew|grows|partnered|partner|scaled|negotiated|acquired|expanded|restructured|redesigned|executed|exceeded|onboarded|oversaw|oversee|handled|trained|established|achieved|generated|optimised|optimized|streamlined|spearheaded|organised|organized|monitored|planned|identified|lifted|doubled|tripled|converted|consolidated|negotiate)\b/iu';

    private const HEADING = '/^(professional summary|summary|profile|experience|work experience|employment|education|qualifications|skills|technical skills|projects|certifications|languages|achievements)[:\s]*$/iu';

    private const DEGREE = '/\b(bachelor|master|b\.tech|m\.tech|degree|mba|phd|licen[sc]e|registered nurse)\b/iu';

    private static ?array $catalog = null;

    private static ?array $stop = null;

    private static function catalog(): array
    {
        if (self::$catalog === null) {
            $rows = json_decode((string) file_get_contents(resource_path('data/ats-concepts.json')), true) ?: [];
            self::$catalog = array_map(function ($c) {
                $alts = implode('|', array_map(fn ($a) => preg_quote($a, '~'), $c[2]));

                return ['name' => $c[0], 'group' => $c[1], 're' => '~(^|[^\p{L}\p{N}])(?:'.$alts.')(?=$|[^\p{L}\p{N}])~iu'];
            }, $rows);
        }

        return self::$catalog;
    }

    private static function trim(string $s): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $s);
    }

    /** @return string[] */
    private static function words(string $s): array
    {
        self::$stop ??= array_flip(explode(' ', self::STOP));
        preg_match_all('/[\p{L}\p{N}+#]+/u', mb_strtolower($s), $m);

        return array_values(array_filter($m[0], fn ($w) => mb_strlen($w) > 2 && ! isset(self::$stop[$w])));
    }

    /** @return array<int,array{text:string,section:string}> */
    private static function segments(string $text): array
    {
        $section = 'Resume';
        $out = [];
        $lines = array_slice(preg_split('/\n+/', $text), 0, 1200);
        foreach ($lines as $raw) {
            $line = self::trim((string) preg_replace('/^\s*(?:[•*\-]\s*|[0-9]+[.)]\s+)/u', '', $raw));
            if ($line === '') {
                continue;
            }
            if (preg_match(self::HEADING, $line)) {
                $section = (string) preg_replace('/:$/', '', $line);

                continue;
            }
            foreach (preg_split('/(?<=[.!?;])\s+(?=[A-Z])/u', $line) as $part) {
                if (self::trim($part) !== '') {
                    $out[] = ['text' => mb_substr(self::trim($part), 0, 1400), 'section' => $section];
                }
            }
        }

        return $out;
    }

    private static function negated(string $text, string $re): bool
    {
        if (! preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }
        $idx = mb_strlen(substr($text, 0, $m[0][1]));
        $start = max(0, $idx - 50);
        $before = mb_substr($text, $start, $idx + 1 - $start);

        return (bool) preg_match('/\b(no|not|without|lack|lacking|learning|beginner|unfamiliar)\b[^.!?;]*$/iu', $before);
    }

    private static function priority(string $q): string
    {
        if (preg_match('/\b(preferred|desirable|nice to have|bonus|advantage)\b/iu', $q)) {
            return 'Preferred';
        }

        return preg_match('/\b(must|required|essential|mandatory|minimum)\b/iu', $q) ? 'Required' : 'Role requirement';
    }

    private static function grade(?array $row): string
    {
        if (! $row) {
            return 'not_found';
        }
        if (preg_match('/skills/iu', $row['section']) || ! preg_match(self::ACTION, $row['text']) || count(self::words($row['text'])) < 6) {
            return 'mentioned';
        }

        return 'evidenced';
    }

    private static function find(array $rows, callable $fn): ?array
    {
        foreach ($rows as $r) {
            if ($fn($r)) {
                return $r;
            }
        }

        return null;
    }

    private const JOB_SECTION = '/^(role overview|the role|about the role|about this role|about the job|about you|job (description|summary|overview|responsibilities)|key responsibilities|responsibilities|duties|what you(\'| wi)ll do|requirements|qualifications|desired candidate profile|candidate profile|who you are|what we(\'re| are) looking for|key skills|skills|must have|nice to have|preferred|experience|eligibility|education)\b/iu';

    /** Plain text as a reader sees it: entities decoded, odd spaces and zero-width marks removed. */
    public static function clean(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace(['/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '/[\x{00A0}\x{2007}\x{202F}]/u', "/\r\n?/"], ['', ' ', "\n"], $s);
    }

    private static function headingLike(string $line): bool
    {
        return mb_strlen($line) <= 70 && ! preg_match('/[.!;,]$/u', $line) && count(preg_split('/\s+/u', $line)) <= 8;
    }

    /**
     * The part of a job posting that describes the job. "About the company", "Why join us" and benefits blocks are
     * marketing text: they are left out so they are not scored as requirements or keywords.
     */
    public static function jobFocus(string $jobText): string
    {
        $out = [];
        $skip = '';
        foreach (preg_split('/\n/u', $jobText) as $raw) {
            $line = self::trim((string) preg_replace('/^\s*(?:[•*\-–]\s*|[0-9]+[.)]\s+)/u', '', $raw));
            $plain = (string) preg_replace('/[:?]+$/u', '', $line);
            if ($line !== '' && self::headingLike($line)) {
                if (preg_match('/^(about(?! (the|this) (role|job|position)| you)\b.*|why (join|work|us)\b.*|(our )?(benefits|perks)( (and|&) benefits)?|what we offer.*|who we are|our (story|mission|values|culture)|life at .*|equal opportunit.*)$/iu', $plain)) {
                    $skip = preg_match('/^(about|who we are|our )/iu', $plain) ? 'about' : 'benefits';

                    continue;
                }
                if ($skip !== '' && (preg_match(self::JOB_SECTION, $plain) || $skip === 'about')) {
                    $skip = '';
                }
            }
            if ($skip === '') {
                $out[] = $raw;
            }
        }
        $focus = trim(implode("\n", $out));

        return mb_strlen($focus) >= 80 ? $focus : $jobText;
    }

    /** Words of the employer's own name ("About Acme Corp", "Company: Acme"), which a resume is not expected to contain. */
    private static function companyWords(string $jobText): array
    {
        $names = [];
        foreach (preg_split('/\n/u', $jobText) as $raw) {
            $line = self::trim($raw);
            if (preg_match('/^about\s+(?!the |this |you\b|us\b)(.{2,80}?)[:?]*$/iu', $line, $m) && self::headingLike($line)) {
                $names[] = $m[1];
            } elseif (preg_match('/^(?:company|organisation|organization|employer)\s*[:\-–]\s*(.{2,80})$/iu', $line, $m)) {
                $names[] = $m[1];
            }
        }
        $out = [];
        foreach ($names as $n) {
            preg_match_all('/[A-Za-z][A-Za-z0-9&]+/', $n, $m);
            foreach ($m[0] as $w) {
                if (strlen($w) > 1 && ! in_array(strtolower($w), ['the', 'and', 'pvt', 'ltd', 'private', 'limited', 'inc', 'llp', 'of', 'for'], true)) {
                    $out[self::stem(strtolower($w))] = true;
                }
            }
        }

        return $out;
    }

    /** Best guess at the job title and employer from the posting itself, used to label a check when the boxes are left empty. */
    public static function headline(string $jobText): array
    {
        $jobText = self::clean($jobText);
        $line = self::jobTitle($jobText);
        $parts = preg_split('/\s+(?:–|—|-|\||@|at)\s+/u', $line, 2);
        $title = mb_strlen($parts[0] ?? '') <= 80 ? self::trim($parts[0] ?? '') : '';
        $company = self::trim($parts[1] ?? '');
        if ($company === '' && preg_match('/^about\s+(?!the |this |you\b|us\b)(.{2,80}?)[:?]*$/imu', $jobText, $m)) {
            $company = self::trim($m[1]);
        }

        return [mb_substr($title, 0, 180), mb_substr($company, 0, 180)];
    }

    /** The posting's own title line, used to weight keywords. Typed fields do not change the score. */
    public static function jobTitle(string $jobText): string
    {
        foreach (preg_split('/\n/u', $jobText) as $raw) {
            $line = self::trim($raw);
            if ($line === '' || preg_match('/^(job description|jd|job title|position|role)[:\s]*$/iu', $line)) {
                continue;
            }
            $line = (string) preg_replace('/^(job title|position|role)\s*[:\-–]\s*/iu', '', $line);

            return mb_strlen($line) <= 100 && ! preg_match('/[.!?;]$/u', $line) && count(preg_split('/\s+/u', $line)) <= 12 ? $line : '';
        }

        return '';
    }

    public static function analyse(string $resumeText, string $jobText): array
    {
        if (mb_strlen($resumeText) > 120000 || mb_strlen($jobText) > 40000) {
            throw new \InvalidArgumentException('Use a resume under 120,000 characters and a job description under 40,000 characters.');
        }
        $resume = self::segments($resumeText);
        $job = self::segments(self::jobFocus($jobText));
        $requirements = [];
        foreach (self::catalog() as $c) {
            $source = self::find($job, fn ($r) => preg_match($c['re'], $r['text']) && ! self::negated($r['text'], $c['re']));
            if (! $source) {
                continue;
            }
            $candidates = array_values(array_filter($resume, fn ($r) => preg_match($c['re'], $r['text']) && ! self::negated($r['text'], $c['re'])));
            $evidenced = array_values(array_filter($candidates, fn ($r) => self::grade($r) === 'evidenced'));
            $rest = array_values(array_filter($candidates, fn ($r) => self::grade($r) !== 'evidenced'));
            $evidence = ($evidenced[0] ?? $rest[0] ?? null);
            $status = self::grade($evidence);
            $requirements[] = ['name' => $c['name'], 'group' => $c['group'], 'source' => $source['text'], 'priority' => self::priority($source['text']), 'status' => $status, 'evidence' => $evidence['text'] ?? '', 'section' => $evidence['section'] ?? '', 'method' => 'Curated related terms + sentence context',
                'advice' => $status === 'evidenced' ? 'Check that this example accurately explains your own contribution and outcome.' : ($status === 'mentioned' ? 'Add one genuine example showing how you used this capability, your contribution and the result.' : 'Decide whether you have relevant experience. Add a truthful example if you do; otherwise treat this as a role gap.')];
        }
        $yearsRe = '/([0-9]{1,2})(?:\s*[-–]\s*[0-9]{1,2})?\s*\+?\s*(?:years?|yrs?)\b/iu';
        $yearsRow = self::find($job, fn ($r) => preg_match($yearsRe, $r['text']) && preg_match('/\b(experience|exp|background|worked|working|hands-on)\b/iu', $r['text']));
        if ($yearsRow && preg_match($yearsRe, $yearsRow['text'], $yj)) {
            preg_match_all('/([0-9]{1,2}(?:\.[0-9]+)?)\s*\+?\s*(?:years?|yrs?)\b/iu', $resumeText, $ym, PREG_SET_ORDER);
            $best = null;
            foreach ($ym as $b) {
                if ((float) $b[1] > (float) ($best[1] ?? 0)) {
                    $best = $b;
                }
            }
            $target = (int) $yj[1];
            $ev = $best ? (self::find($resume, fn ($r) => str_contains($r['text'], $best[0]))['text'] ?? $best[0]) : '';
            $requirements[] = ['name' => 'Experience duration · '.$target.'+ years', 'group' => 'Eligibility', 'source' => $yearsRow['text'], 'priority' => self::priority($yearsRow['text']), 'status' => $best ? 'review' : 'not_found', 'evidence' => $ev, 'section' => 'Experience', 'method' => 'Explicit duration statements only',
                'advice' => $best ? 'Your resume explicitly mentions '.$best[1].' years. Verify whether those years are in the required field; overlapping roles and dates are not calculated.' : 'Confirm the required duration and your relevant dates. No duration was inferred from dates or qualifications.'];
        }
        $degree = self::find($job, fn ($r) => preg_match(self::DEGREE, $r['text']));
        if ($degree) {
            $ev = self::find($resume, fn ($r) => preg_match(self::DEGREE, $r['text']));
            $requirements[] = ['name' => 'Education or professional eligibility', 'group' => 'Eligibility', 'source' => $degree['text'], 'priority' => self::priority($degree['text']), 'status' => $ev ? 'review' : 'not_found', 'evidence' => $ev['text'] ?? '', 'section' => $ev['section'] ?? '', 'method' => 'Qualification mentions require manual verification',
                'advice' => 'Compare the exact discipline, level, licence and eligibility requirement. A qualification mention does not establish equivalence.'];
        }
        $sources = array_flip(array_column($requirements, 'source'));
        foreach ($job as $row) {
            if (isset($sources[$row['text']]) || mb_strlen($row['text']) < 35 || ! preg_match('/(must|required|responsib|develop|deliver|manage|design|support|build|experience|ability|knowledge|proficien)/iu', $row['text'])) {
                continue;
            }
            $keys = array_values(array_unique(self::words($row['text'])));
            if (count($keys) < 4) {
                continue;
            }
            $best = null;
            $bestN = 0;
            foreach ($resume as $cand) {
                $set = array_flip(self::words($cand['text']));
                $n = count(array_filter($keys, fn ($k) => isset($set[$k])));
                if ($n > $bestN) {
                    $best = $cand;
                    $bestN = $n;
                }
            }
            $plausible = $bestN >= max(3, (int) ceil(count($keys) * .35));
            $requirements[] = ['name' => mb_substr($row['text'], 0, 85).(mb_strlen($row['text']) > 85 ? '…' : ''), 'group' => 'Additional requirement', 'source' => $row['text'], 'priority' => self::priority($row['text']), 'status' => 'review', 'evidence' => $plausible ? $best['text'] : '', 'section' => $plausible ? $best['section'] : '', 'method' => 'Unclassified requirement — human review',
                'advice' => 'Read this full requirement against your experience. Related wording is shown only as a starting point, not confirmation of a match.'];
            if (count($requirements) >= 32) {
                break;
            }
        }
        $rows = array_slice($requirements, 0, 32);
        $counts = [];
        foreach (['evidenced', 'mentioned', 'not_found', 'review'] as $k) {
            $counts[$k] = count(array_filter($rows, fn ($r) => $r['status'] === $k));
        }
        $wordCount = preg_match_all('/\S+/u', $resumeText);
        $impact = array_values(array_filter($resume, fn ($r) => preg_match(self::ACTION, $r['text']) && preg_match('/[0-9]+\s*(%|\+|hours?|days?|weeks?|applications?|interviews?|users?|customers?|projects?|people|₹|\$)|[₹$]\s*[0-9]+/iu', $r['text'])));
        $emailRe = '/[A-Za-z0-9_.+-]+@[A-Za-z0-9_.-]+\.[A-Za-z0-9_]{2,}/';
        $structure = [
            ['name' => 'Contact information', 'ok' => (bool) preg_match($emailRe, $resumeText), 'detail' => 'An email address can be detected in the extracted text.'],
            ['name' => 'Experience or projects', 'ok' => (bool) preg_match('/\b(experience|projects?|internship)\b/iu', $resumeText), 'detail' => 'Section or experience wording is present; extraction cannot verify the visual reading order.'],
            ['name' => 'Outcome detail', 'ok' => count($impact) > 0, 'detail' => count($impact) ? count($impact).' action statements contain numbers or outcome detail. Confirm every number.' : 'Consider adding a verifiable result where it improves an experience statement.'],
            ['name' => 'Text length', 'ok' => $wordCount >= 150 && $wordCount <= 1000, 'detail' => $wordCount.' words extracted. Length is a review signal, not a page count or quality score.'],
        ];
        $parameter = fn ($name, $pass, $evidence, $action, $group = 'Resume clarity') => ['name' => $name, 'status' => $pass ? 'Detected' : 'Review', 'evidence' => $evidence, 'action' => $action, 'group' => $group];
        $lines = array_values(array_filter(array_map([self::class, 'trim'], explode("\n", $resumeText)), fn ($x) => $x !== ''));
        preg_match($emailRe, $resumeText, $email);
        preg_match('/(?:\+[0-9]{1,3}[ -]?)?(?:[0-9][ ()-]?){9,14}[0-9]/', $resumeText, $phone);
        preg_match_all('/(?:https?:\/\/|www\.|linkedin\.com|github\.com|behance\.net|dribbble\.com)\S+/iu', $resumeText, $lk);
        $links = $lk[0];
        $long = array_filter($resume, fn ($l) => count(preg_split('/\s+/u', $l['text'])) > 45);
        $lower = array_map('mb_strtolower', $lines);
        $dupes = [];
        foreach ($lines as $i => $l) {
            if (mb_strlen($l) > 35 && array_search($lower[$i], $lower, true) !== $i) {
                $dupes[] = $l;
            }
        }
        preg_match_all('/\b(?:19|20)[0-9]{2}\b/', $resumeText, $dm);
        $dates = $dm[0];
        $actions = array_filter($resume, fn ($r) => preg_match(self::ACTION, $r['text']));
        $reqNotDone = array_filter($rows, fn ($r) => $r['priority'] === 'Required' && $r['status'] !== 'evidenced');
        $resLen = mb_strlen($resumeText);
        $parameters = [
            $parameter('Readable input', $resLen >= 150, $resLen.' characters; '.$wordCount.' words.', 'Review extraction for missing lines and symbols.', 'Import quality'),
            $parameter('Email', (bool) $email, $email[0] ?? 'No email pattern found.', 'Add a contact email and check spelling.'),
            $parameter('Phone', (bool) $phone, $phone[0] ?? 'No phone pattern found.', 'Confirm your contact number and country code.'),
            $parameter('Portfolio or professional links', count($links) > 0, implode(' · ', array_slice($links, 0, 3)) ?: 'No link pattern found.', 'For creative roles, link to relevant work. Open each URL yourself.'),
            $parameter('Experience heading', (bool) preg_match('/\b(experience|employment|work history|internship)\b/iu', $resumeText), 'English experience heading check.', 'Use a familiar heading; extraction may omit styling.'),
            $parameter('Education heading', (bool) preg_match('/\beducation|qualifications\b/iu', $resumeText), 'English education heading check.', 'Make the qualification, institution and dates easy to find.'),
            $parameter('Projects heading', (bool) preg_match('/\bprojects?|portfolio\b/iu', $resumeText), 'Project or portfolio wording check.', 'Use projects where they provide stronger role evidence.'),
            $parameter('Action-led evidence', count($actions) > 0, count($actions).' sentences contain a recognised action verb.', 'Explain your contribution, context and result.'),
            $parameter('Outcome detail', count($impact) > 0, count($impact).' action statements include numbers or outcome detail.', 'Add only numbers you can substantiate.'),
            $parameter('Skills in context', $counts['evidenced'] > 0, $counts['evidenced'].' requirement evidence candidates.', 'Connect skills to specific work instead of listing them alone.', 'Role evidence'),
            $parameter('Skill-list mentions', $counts['mentioned'] === 0, $counts['mentioned'].' requirements appear as mentions only.', 'Strengthen relevant mentions with truthful examples.', 'Role evidence'),
            $parameter('Required requirements to review', count($reqNotDone) === 0, count($reqNotDone).' required items need review.', 'Prioritise these before optional requirements.', 'Role evidence'),
            $parameter('Dates present', count($dates) > 0, implode(', ', array_slice(array_values(array_unique($dates)), 0, 10)) ?: 'No year pattern found.', 'Verify the actual sequence, overlapping roles and gaps manually.'),
            $parameter('Repeated content', count($dupes) === 0, count($dupes).' repeated lines found.', 'Remove accidental duplicates; repeated headings can be legitimate.'),
            $parameter('Long sentences', count($long) === 0, count($long).' extracted sentences exceed 45 words.', 'Split dense statements if it helps readability.'),
            $parameter('Length review', $wordCount >= 150 && $wordCount <= 1000, $wordCount.' words; PDF page count is not inferred.', 'Choose length based on relevant experience, not a score.'),
            ['name' => 'Photo, colour, layout and reading order', 'status' => 'Manual check', 'evidence' => 'Plain text cannot establish visual quality or ATS parsing.', 'action' => 'Inspect the full PDF, copy its text, and follow the employer’s format instructions.', 'group' => 'Layout'],
            ['name' => 'Accuracy and eligibility', 'status' => 'Manual check', 'evidence' => 'Qualifications, years, claims and employer criteria are not verified.', 'action' => 'Check original documents and the full job description.', 'group' => 'Eligibility'],
        ];
        $languageNote = preg_match('/[\x{0900}-\x{097f}\x{0980}-\x{09ff}]/u', $resumeText.$jobText)
            ? 'This review uses English-language rules. Hindi or Bengali content requires manual review; missing detections are not a capability judgement.'
            : 'The review uses English-language rules and a curated capability dictionary. Unrecognised specialist requirements remain manual checks.';
        $open = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'evidenced'));
        $actionsList = array_slice(array_merge(array_values(array_filter($open, fn ($r) => $r['priority'] === 'Required')), array_values(array_filter($open, fn ($r) => $r['priority'] !== 'Required'))), 0, 5);

        return ['parameters' => $parameters, 'languageNote' => $languageNote, 'version' => 'Evidence review v2', 'requirements' => $rows, 'counts' => $counts, 'wordCount' => $wordCount, 'structure' => $structure, 'impact' => array_slice($impact, 0, 3),
            'unclassified' => count(array_filter($rows, fn ($r) => $r['group'] === 'Additional requirement')), 'actions' => $actionsList,
            'method' => 'Deterministic analysis of the two texts you supplied. Related-term groups and action context identify evidence candidates. Unclassified requirements and eligibility need your review. No web research, employer ATS access, credential verification or hiring prediction is performed.'];
    }

    private const KW_STOP = 'the a an and or of for to in on at by with as from be is are was were have has this that your our you we will must should required requires requirements about into over under their they them his her its it who whom which what when where while also able ability across all any can could may might more most other some such than then there these those through using use used within without would work working works role roles job jobs team teams candidate candidates company looking join skills skill strong good great excellent knowledge experience experienced years year plus etc including include includes ensure ensuring manage managing responsible responsibilities responsibility preferred nice have having bonus apply application applications position opportunity opportunities benefits offer offers hybrid remote office full time part employee employees environment business new help support provide providing well one two three first based related relevant like both each every per via day days month months week weeks need needs needed make making get getting take taking high level highly ideal best build write present develop design deliver create lead drive own maintain participate collaborate communicate perform conduct identify improve analyse analyze understand deep proven track record seeking hire hiring description overview type location employment extensive desired profile monitor track coordinate address prepare thrive possess seamless smooth regular periodic overall key ideally minimum maximum significant advantage willingness frequent fast paced growth across';

    private static function stem(string $w): string
    {
        $w = (string) preg_replace('/(?<=[a-z]{3})(s|x|ch|sh)es$/', '$1', $w); // campuses → campus, so both forms meet

        return strlen($w) > 5 ? (string) preg_replace('/(ing|ed|es|ly|ions?|ment|s)$/', '', $w) : (string) preg_replace('/s$/', '', $w);
    }

    /** Which of the job's most important words appear in the resume. */
    public static function keywords(string $resumeText, string $jobText, string $title = ''): array
    {
        $stop = array_flip(explode(' ', self::KW_STOP));
        // two-word tool names count as one keyword ("Power BI", not "Power")
        $pairs = ['Power BI' => 'PowerBI', 'Google Analytics' => 'GoogleAnalytics', 'Google Ads' => 'GoogleAds', 'SQL Server' => 'SQLServer', 'Microsoft Office' => 'MSOffice', 'MS Office' => 'MSOffice', 'Visual Studio' => 'VisualStudio', 'Adobe Photoshop' => 'Photoshop', 'Tally Prime' => 'TallyPrime', 'Apache Spark' => 'ApacheSpark', 'Spring Boot' => 'SpringBoot'];
        $show = ['PowerBI' => 'Power BI', 'GoogleAnalytics' => 'Google Analytics', 'GoogleAds' => 'Google Ads', 'SQLServer' => 'SQL Server', 'MSOffice' => 'MS Office', 'VisualStudio' => 'Visual Studio', 'TallyPrime' => 'Tally Prime', 'ApacheSpark' => 'Apache Spark', 'SpringBoot' => 'Spring Boot'];
        foreach ($pairs as $a => $b) {
            $resumeText = (string) preg_replace('/\b'.preg_quote($a, '/').'\b/i', $b, $resumeText);
            $jobText = (string) preg_replace('/\b'.preg_quote($a, '/').'\b/i', $b, $jobText);
        }
        $re = '/[A-Za-z][A-Za-z0-9+#.]*[A-Za-z0-9+#]|[A-Za-z]/';
        preg_match_all($re, $resumeText, $rm);
        $have = [];
        foreach ($rm[0] as $w) {
            $have[self::stem(strtolower($w))] = true;
        }
        $freq = [];
        $shown = [];
        $employer = self::companyWords($jobText);
        $jobText = self::jobFocus($jobText);
        $tl = mb_strtolower($title);
        preg_match_all($re, $jobText, $jm, PREG_OFFSET_CAPTURE);
        foreach ($jm[0] as [$raw, $byte]) {
            $w = strtolower($raw);
            if (strlen($w) < 3 || isset($stop[$w])) {
                continue;
            }
            $before = $byte > 0 ? (string) preg_replace('/[ \t•*\-–]+$/u', '', mb_strcut($jobText, max(0, $byte - 12), min(12, $byte))) : '';
            $starts = $before === '' || (bool) preg_match('/[.!?:\n]$/', $before);
            $named = (bool) preg_match('/[A-Z].*[A-Z]|[+#]/', $raw) || (preg_match('/^[A-Z][a-z]/', $raw) && ! $starts);
            $k = self::stem($w);
            if (isset($employer[$k])) {
                continue;
            }
            $cur = $freq[$k] ?? ['n' => 0, 'bonus' => 0];
            $cur['n']++;
            if ($named) {
                $cur['bonus'] = max($cur['bonus'], 2);
            }
            if ($tl !== '' && str_contains($tl, $w)) {
                $cur['bonus'] += 2;
            }
            $freq[$k] = $cur;
            if (! isset($shown[$k]) || $named) {
                $shown[$k] = $raw;
            }
        }
        $ranked = [];
        foreach ($freq as $k => $v) {
            if ($v['n'] >= 2 || $v['bonus'] > 0) {
                $ranked[] = [$k, $v['n'] + $v['bonus']];
            }
        }
        usort($ranked, fn ($a, $b) => $b[1] <=> $a[1]);
        $ranked = array_slice($ranked, 0, 30);
        $found = [];
        $missing = [];
        foreach ($ranked as [$k]) {
            $label = $show[$shown[$k]] ?? $shown[$k];
            if (isset($have[$k])) {
                $found[] = $label;
            } else {
                $missing[] = $label;
            }
        }

        return ['total' => count($ranked), 'found' => $found, 'missing' => $missing, 'pct' => $ranked ? (int) round(count($found) / count($ranked) * 100) : null];
    }

    /** Match estimate 0–100: requirement evidence (Required 3, other 2, Preferred 1) blended with keyword coverage. */
    public static function score(array $result, array $kw): int
    {
        $weight = ['Required' => 3, 'Preferred' => 1];
        $credit = ['evidenced' => 1, 'mentioned' => 0.5, 'review' => 0.25, 'not_found' => 0];
        $got = 0;
        $total = 0;
        foreach ($result['requirements'] as $x) {
            $w = $weight[$x['priority']] ?? 2;
            $total += $w;
            // a requirement to review with related wording in the resume counts for more than one with nothing related
            $got += $w * ($x['status'] === 'review' && ($x['evidence'] ?? '') !== '' ? 0.5 : ($credit[$x['status']] ?? 0));
        }
        $ev = $total ? $got / $total * 100 : null;
        if ($ev === null && $kw['pct'] === null) {
            return 0;
        }
        if ($ev === null) {
            return (int) $kw['pct'];
        }
        if ($kw['pct'] === null) {
            return (int) round($ev);
        }
        $w = min(0.7, 0.1 * count($result['requirements']));

        return (int) round($w * $ev + (1 - $w) * $kw['pct']);
    }

    /** @return string[] what the customer is lacking, most important first */
    public static function gaps(array $result, array $kw): array
    {
        $labels = ['evidenced' => 'Evidence found', 'mentioned' => 'Mentioned only', 'not_found' => 'Not found', 'review' => 'Needs your review'];
        $weight = ['Required' => 3, 'Preferred' => 1];
        $order = ['not_found' => 0, 'mentioned' => 1, 'review' => 2];
        $out = [];
        if ($kw['missing']) {
            $out[] = mb_substr('Keywords · Missing from your resume: '.implode(', ', array_slice($kw['missing'], 0, 12)).'. Add them only where they truthfully describe your experience.', 0, 240);
        }
        $open = array_values(array_filter($result['requirements'], fn ($x) => $x['status'] !== 'evidenced'));
        usort($open, fn ($a, $b) => (($weight[$b['priority']] ?? 2) <=> ($weight[$a['priority']] ?? 2)) ?: (($order[$a['status']] ?? 3) <=> ($order[$b['status']] ?? 3)));
        foreach (array_slice($open, 0, 30) as $x) {
            $out[] = mb_substr($x['priority'].' · '.$x['name'].' — '.$labels[$x['status']].'. '.($x['advice'] ?? ''), 0, 240);
        }
        foreach (array_slice(array_values(array_filter($result['parameters'], fn ($x) => $x['status'] === 'Review')), 0, 6) as $x) {
            $out[] = mb_substr('Resume format · '.$x['name'].' — '.$x['action'], 0, 240);
        }

        return array_slice($out, 0, 40);
    }

    /** Plain text of a saved resume, in the order a reader (or an ATS) would see it. */
    public static function resumeText(array $d): string
    {
        $p = $d['personal'] ?? [];
        $bul = fn ($t) => array_values(array_filter(array_map(fn ($l) => trim((string) preg_replace('/^\s*[•*-]\s*/u', '', $l)), explode("\n", (string) $t))));
        $when = fn ($r) => implode(' - ', array_filter([$r['start'] ?? '', ! empty($r['current']) ? 'Present' : ($r['end'] ?? $r['date'] ?? '')]));
        $out = [$p['name'] ?? '', $p['role'] ?? '', implode(' | ', array_filter([$p['email'] ?? '', $p['phone'] ?? '', $p['city'] ?? '', $p['linkedin'] ?? '', $p['portfolio'] ?? '']))];
        if (! empty($d['summary'])) {
            array_push($out, 'SUMMARY', $d['summary']);
        }
        if (! empty($d['skills'])) {
            array_push($out, 'SKILLS', $d['skills']);
        }
        if (! empty($d['experience'])) {
            $out[] = 'EXPERIENCE';
            foreach ($d['experience'] as $r) {
                $out[] = implode(' | ', array_filter([$r['role'] ?? '', $r['company'] ?? '', $r['city'] ?? '', $when($r)]));
                foreach ($bul($r['bullets'] ?? $r['details'] ?? '') as $b) {
                    $out[] = '• '.$b;
                }
            }
        }
        if (! empty($d['projects'])) {
            $out[] = 'PROJECTS';
            foreach ($d['projects'] as $r) {
                $out[] = implode(' | ', array_filter([$r['name'] ?? '', $r['tools'] ?? '', $r['link'] ?? '']));
                foreach ($bul($r['bullets'] ?? $r['details'] ?? '') as $b) {
                    $out[] = '• '.$b;
                }
            }
        }
        if (! empty($d['education'])) {
            $out[] = 'EDUCATION';
            foreach ($d['education'] as $r) {
                $out[] = implode(' | ', array_filter([$r['degree'] ?? '', $r['school'] ?? '', $when($r)]));
                foreach ($bul($r['details'] ?? $r['bullets'] ?? '') as $b) {
                    $out[] = $b;
                }
            }
        }
        if (! empty($d['certifications'])) {
            $out[] = 'CERTIFICATIONS';
            foreach ($d['certifications'] as $r) {
                $out[] = implode(' | ', array_filter([$r['name'] ?? '', $r['issuer'] ?? '', $r['date'] ?? '']));
            }
        }
        if (! empty($d['languages'])) {
            array_push($out, 'LANGUAGES', $d['languages']);
        }
        if (! empty($d['awards'])) {
            array_push($out, 'AWARDS', $d['awards']);
        }

        return implode("\n", array_filter(array_map(fn ($x) => is_string($x) ? $x : (string) $x, $out), fn ($x) => trim($x) !== ''));
    }

    /** Identifies the text a check reads, so two resumes with the same words can be told apart from two that differ. */
    public static function fingerprint(array $resumeData): string
    {
        return sha1((string) preg_replace('/\s+/u', ' ', mb_strtolower(self::clean(self::resumeText($resumeData)))));
    }

    /** One call for the whole report. */
    public static function report(array $resumeData, string $jobText, string $title = ''): array
    {
        $text = self::clean(self::resumeText($resumeData));
        $jobText = self::clean($jobText);
        // the score depends only on the two texts: the optional title and company boxes do not move it
        $title = self::jobTitle($jobText);
        if (mb_strlen($text) < 60) {
            throw new \InvalidArgumentException('This resume has too little content to check. Add your experience and skills in the builder, then try again.');
        }
        $result = self::analyse($text, $jobText);
        $kw = self::keywords($text, $jobText, $title);
        if (! $result['requirements'] && $kw['total'] < 5) {
            throw new \InvalidArgumentException('No clear requirements were found in this job. Make sure the page or file contains the responsibilities and qualifications.');
        }

        return ['score' => self::score($result, $kw), 'keywords' => $kw, 'gaps' => self::gaps($result, $kw), 'result' => $result];
    }
}
