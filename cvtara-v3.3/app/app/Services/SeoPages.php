<?php

namespace App\Services;

/**
 * Programmatic SEO pages, built from resources/data/seo-pages.json (source: tools/seo/*.py).
 *
 * Every page is server-rendered with breadcrumbs, an "On this page" list, FAQ, a product call to action and links that tie
 * it into its hub and its neighbours: hub → category → role, role ↔ interview and cover-letter pages, India → state → city →
 * city × role, and every page → ATS checker and pricing. Paths always end with a slash so the ready-made page cache can
 * store them.
 *
 * Writing rules (checked by tests/suites/s23_seo_pages.py): the main phrase of a page appears in its title, H1, lead and
 * about one heading, not in every heading and link; link lists use short names ("Pune", "Accountant"), not the full
 * keyword again; and text that would be the same on hundreds of pages is kept to the site chrome.
 */
class SeoPages
{
    private static ?array $data = null;

    /** Path prefixes owned by this class (used by the controller and the page cache). */
    public const PREFIXES = ['resume-examples', 'resume-templates', 'cover-letter-examples', 'interview-questions', 'resume-builder', 'resume', 'resume-words', 'ats-checker'];

    /** Existing guides and calculators that the programmatic pages link to. */
    private const GUIDES = [
        'summary' => ['/guides/resume/professional-summary-guide/', 'How to write a professional summary'],
        'bullets' => ['/guides/resume/achievements-bullet-points/', 'Turn duties into achievement bullet points'],
        'skills' => ['/guides/resume/skills-section-keywords/', 'Choosing skills and keywords for your resume'],
        'ats' => ['/guides/resume/ats-friendly-format-india/', 'ATS-friendly resume format for India'],
        'fresher' => ['/guides/resume/no-experience-format/', 'Resume format with no experience'],
        'projects' => ['/guides/resume/fresher-projects-guide/', 'How freshers should present projects'],
        'verbs' => ['/guides/resume/action-verbs-list/', 'Action verbs list for resumes'],
        'pdfword' => ['/guides/resume/pdf-vs-word-ats/', 'PDF or Word: which file to send'],
        'gap' => ['/guides/resume/gap-in-employment-explanation/', 'How to explain a gap in employment'],
        'tmay' => ['/guides/interview/tell-me-about-yourself/', 'How to answer "Tell me about yourself"'],
        'star' => ['/guides/interview/star-method-examples/', 'STAR method examples'],
        'ask' => ['/guides/interview/questions-to-ask-them/', 'Questions to ask the interviewer'],
        'thanks' => ['/guides/interview/thank-you-email-template/', 'Thank-you email after an interview'],
        'salary' => ['/guides/interview/salary-expectations-answer/', 'How to answer salary expectations'],
        'coverEmail' => ['/guides/cover-letter/short-email-format/', 'Short cover letter email format'],
        'followup' => ['/guides/jobs/follow-up-email-template/', 'Follow-up email after applying'],
        'tracker' => ['/guides/jobs/application-tracker-method/', 'A simple job application tracker'],
        'scams' => ['/guides/jobs/remote-job-scams-signs/', 'Signs of a job scam'],
        'ctc' => ['/calculators/ctc-to-in-hand-salary-calculator/', 'CTC to in-hand salary calculator'],
        'col' => ['/calculators/cost-of-living-calculator-by-city/', 'Cost of living calculator by city'],
    ];

    /** The page-type hubs, in the order the site footer lists them. */
    public const HUBS = [
        ['/resume-examples/', 'Resume examples'],
        ['/resume-templates/', 'Resume templates'],
        ['/cover-letter-examples/', 'Cover letter examples'],
        ['/interview-questions/', 'Interview questions'],
        ['/resume-words/', 'Resume words'],
        ['/resume-builder/india/', 'Resume help by city'],
        ['/ats-checker/', 'Free ATS check'],
    ];

    private const ATS_DEF = 'ATS (applicant tracking system, the software many employers use to store, search and filter resumes)';

    public static function data(): array
    {
        return self::$data ??= json_decode((string) file_get_contents(resource_path('data/seo-pages.json')), true);
    }

    public static function owns(string $path): bool
    {
        return (bool) preg_match('#^('.implode('|', array_map('preg_quote', self::PREFIXES)).')(/|$)#', $path);
    }

    /** Every page this class can render, as cache/sitemap paths with a trailing slash. */
    public static function paths(): array
    {
        $d = self::data();
        $p = ['resume-examples/', 'resume-templates/', 'cover-letter-examples/', 'interview-questions/', 'resume-builder/india/', 'resume-words/', 'ats-checker/'];
        foreach ($d['categories'] as $c => $_) {
            $p[] = "resume-examples/$c/";
            if (self::rolesIn($c)) {
                $p[] = "cover-letter-examples/$c/";
            }
        }
        foreach ($d['roles'] as $r => $role) {
            $p[] = "resume-examples/{$role['c']}/$r/";
            $p[] = "interview-questions/$r/";
        }
        foreach ($d['templates'] as $t => $_) {
            $p[] = "resume-templates/$t/";
        }
        foreach ($d['states'] as $s => $_) {
            $p[] = "resume-builder/$s/";
            $p[] = "resume/$s/government-jobs/";
        }
        foreach ($d['cities'] as $c => $_) {
            $p[] = "resume-builder/$c/";
            foreach (array_keys(self::cityRoles($c)) as $r) {
                $p[] = "resume/$c/$r/";
            }
        }
        foreach ($d['words'] as $w => $_) {
            $p[] = "resume-words/$w/";
        }

        return array_values(array_unique($p));
    }

    /**
     * Where an address that is not a page should go (a permanent redirect), or null. Covers the missing trailing slash and
     * the parent folders people reach by trimming an address: /resume/ and /resume/{city}/.
     */
    public static function redirect(string $path): ?string
    {
        $s = explode('/', trim($path, '/'));
        $d = self::data();
        if ($s[0] === 'resume' && count($s) <= 2) {
            $place = $s[1] ?? '';

            return isset($d['cities'][$place]) || isset($d['states'][$place]) ? "/resume-builder/$place/" : '/resume-builder/india/';
        }

        return null;
    }

    /** Page model for a request path (without leading slash; trailing slash optional), or null. */
    public static function find(string $path): ?array
    {
        $d = self::data();
        $s = explode('/', trim($path, '/'));
        $n = count($s);
        $page = match (true) {
            $s[0] === 'resume-examples' && $n === 1 => self::examplesHub(),
            $s[0] === 'resume-examples' && $n === 2 && isset($d['categories'][$s[1]]) => self::category($s[1]),
            $s[0] === 'resume-examples' && $n === 3 && isset($d['roles'][$s[2]]) && $d['roles'][$s[2]]['c'] === $s[1] => self::role($s[2]),
            $s[0] === 'resume-templates' && $n === 1 => self::templatesHub(),
            $s[0] === 'resume-templates' && $n === 2 && isset($d['templates'][$s[1]]) => self::template($s[1]),
            $s[0] === 'cover-letter-examples' && $n === 1 => self::coverHub(),
            $s[0] === 'cover-letter-examples' && $n === 2 && isset($d['categories'][$s[1]]) && self::rolesIn($s[1]) => self::cover($s[1]),
            $s[0] === 'interview-questions' && $n === 1 => self::interviewHub(),
            $s[0] === 'interview-questions' && $n === 2 && isset($d['roles'][$s[1]]) => self::interview($s[1]),
            $s[0] === 'resume-builder' && $n === 2 && $s[1] === 'india' => self::india(),
            $s[0] === 'resume-builder' && $n === 2 && isset($d['states'][$s[1]]) => self::state($s[1]),
            $s[0] === 'resume-builder' && $n === 2 && isset($d['cities'][$s[1]]) => self::city($s[1]),
            $s[0] === 'resume' && $n === 3 && isset($d['states'][$s[1]]) && $s[2] === 'government-jobs' => self::stateGov($s[1]),
            $s[0] === 'resume' && $n === 3 && isset($d['cities'][$s[1]]) && isset(self::cityRoles($s[1])[$s[2]]) => self::cityRole($s[1], $s[2]),
            $s[0] === 'resume-words' && $n === 1 => self::wordsHub(),
            $s[0] === 'resume-words' && $n === 2 && isset($d['words'][$s[1]]) => self::word($s[1]),
            $s[0] === 'ats-checker' && $n === 1 => self::atsChecker(),
            default => null,
        };
        if ($page) {
            $page['path'] = '/'.implode('/', $s).'/';
            // Changes the owner approved from the SEO agent (Growth page): a new title and description, and links to pages it is pushing up.
            if ($o = SeoAgent::override($page['path'])) {
                $page['title'] = $o['title'] ?? $page['title'];
                $page['description'] = $o['description'] ?? $page['description'];
                if ($links = array_values(array_filter($o['links'] ?? [], fn ($l) => $l[0] !== $page['path'] && self::owns(ltrim($l[0], '/'))))) {
                    $page['related'][] = ['Popular with job seekers', array_map(fn ($l) => [$l[0], $l[1]], $links)];
                }
            }
            $page['title'] = self::fit($page['title'], 50);
            $page['description'] = self::fit($page['description'], 155);
            $page['index'] ??= true;
            $page['cta'] ??= self::cta();
            $page['related'][] = ['Tools', [['/ats-checker/', 'Free ATS resume check'], ['/#pricing', 'Plans and pricing'], ['/templates.html', 'Template gallery'], ['/register', 'Build my resume free']]];
            $page['picker'] = self::pickerLink($s);
        }

        return $page;
    }

    /** Whether a page is offered to search engines (index,follow and listed in the sitemap). */
    public static function indexable(string $path): bool
    {
        return (bool) (self::find($path)['index'] ?? false);
    }

    // ---------------------------------------------------------------- data helpers
    /** "Use this template" target: the guided picker on the gallery, pre-selected for the page's role, field or style. */
    private static function pickerLink(array $s): string
    {
        $d = self::data();
        $q = match (true) {
            $s[0] === 'resume-examples' && count($s) === 3, $s[0] === 'interview-questions' && count($s) === 2 => ['role' => end($s)],
            $s[0] === 'resume-examples' && count($s) === 2 => ['industry' => $s[1]],
            $s[0] === 'resume-templates' && count($s) === 2 => ['style' => $s[1]],
            $s[0] === 'cover-letter-examples' && count($s) === 2 => ['industry' => $s[1]],
            $s[0] === 'resume' && count($s) === 3 && $s[2] === 'government-jobs' => ['role' => 'government-jobs'],
            $s[0] === 'resume' && count($s) === 3 => ($s[2] === 'freshers' ? ['type' => 'fresher', 'role' => 'freshers'] : ['role' => self::cityRoles($s[1])[$s[2]]['role'] ?? $s[2]]),
            default => [],
        };
        $page = $s[0] === 'cover-letter-examples' ? '/cover-letters.html' : '/templates.html';

        return $page.($q ? '?'.http_build_query($q) : '').'#picker';
    }


    private static function rolesIn(string $cat): array
    {
        return array_filter(self::data()['roles'], fn ($r) => $r['c'] === $cat);
    }

    /**
     * City pages: Tier 1 uses the curated list; Tier 2 uses the shared roles plus its local specialty. Every city gets freshers.
     * A row carries a local note when one has been written; pages without one are kept out of search results.
     */
    private static function cityRoles(string $city): array
    {
        $d = self::data();
        $c = $d['cities'][$city];
        if ($c['tier'] === 1) {
            $out = $d['cityRoles'][$city] ?? [];
        } else {
            $out = [];
            foreach ($d['tier2Roles'] as $r) {
                $out[$r] = ['role' => $r, 'note' => $d['tier2Notes'][$city][$r] ?? ''];
            }
            $out[$c['sp'][0]] = ['role' => $c['sp'][0], 'note' => $c['sp'][1]];
        }
        $out['freshers'] = ['role' => 'freshers', 'note' => $c['fn'] ?? ''];

        return $out;
    }

    private static function stateName(string $st): string
    {
        return self::data()['states'][$st]['n'] ?? $st;
    }

    private static function stateOf(string $city): ?string
    {
        $st = self::data()['cities'][$city]['st'];

        return isset(self::data()['states'][$st]) ? $st : null;
    }

    private static function rolePath(string $r): string
    {
        return '/resume-examples/'.self::data()['roles'][$r]['c'].'/'.$r.'/';
    }

    /** Lower-case role words for running text, keeping acronyms such as HR, BPO, IT, QA. */
    private static function lc(string $t): string
    {
        return preg_replace_callback('/\b([A-Z][a-z]+)\b/', fn ($m) => strtolower($m[1]), $t);
    }

    private static function fit(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max - 1);

        return rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' ')), ' ,;:-–').'…';
    }

    /** The first wording that fits, so titles are never cut mid-phrase. */
    private static function pick(array $options, int $max = 50): string
    {
        foreach ($options as $o) {
            if (mb_strlen($o) <= $max) {
                return $o;
            }
        }

        return self::fit(end($options), $max);
    }

    /** A description made of whole sentences: adds sentences while they fit in 155 characters. */
    private static function sentences(string ...$parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            $next = trim($out.' '.$p);
            if (mb_strlen($next) > 155) {
                continue;
            }
            $out = $next;
        }

        return $out !== '' ? $out : self::fit($parts[0], 155);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function ul(array $items, string $class = ''): string
    {
        return '<ul'.($class ? ' class="'.$class.'"' : '').'>'.implode('', array_map(fn ($i) => '<li>'.self::e($i).'</li>', $items)).'</ul>';
    }

    private static function links(array $pairs): string
    {
        return '<ul class="sp-links">'.implode('', array_map(fn ($l) => '<li><a href="'.self::e($l[0]).'">'.self::e($l[1]).'</a></li>', $pairs)).'</ul>';
    }

    private static function cards(array $items): string
    {
        // [path, title, text]
        return '<div class="sp-cards">'.implode('', array_map(fn ($c) => '<a class="sp-card" href="'.self::e($c[0]).'"><b>'.self::e($c[1]).'</b>'.($c[2] ?? '' ? '<span>'.self::e($c[2]).'</span>' : '').'</a>', $items)).'</div>';
    }

    private static function chips(array $items): string
    {
        return '<div class="sp-chips">'.implode('', array_map(fn ($s) => '<span>'.self::e($s).'</span>', $items)).'</div>';
    }

    private static function guides(string ...$keys): array
    {
        return array_map(fn ($k) => self::GUIDES[$k], $keys);
    }

    private static function crumbs(array ...$c): array
    {
        return array_merge([['Home', '/']], $c);
    }

    /** Joins a list for running text: "a, b and c". */
    private static function and(array $items): string
    {
        $items = array_values($items);
        $last = array_pop($items);

        return $items ? implode(', ', $items).' and '.$last : (string) $last;
    }

    /**
     * The product call to action shown in the hero and at the end of the page. Kept as data so the page template has one
     * clearly marked CTA block: [primary [href, label], secondary [href, label], note].
     */
    private static function cta(array $primary = ['/register', 'Build my resume free'], array $secondary = ['/ats-checker/', 'Check my resume for ATS'], string $note = ''): array
    {
        return ['primary' => $primary, 'secondary' => $secondary, 'pricing' => ['/#pricing', 'See plans and pricing'], 'note' => $note ?: 'Start from a template or upload your old resume. Building and previewing is free.'];
    }

    /** A filled sample resume rendered as a document, so readers (and search engines) see real text, not a picture. */
    private static function sample(array $r, string $city = 'Bangalore'): string
    {
        $skills = implode(' · ', array_slice($r['k'], 0, 8));

        return '<figure class="sp-sample" aria-label="Sample '.self::e($r['t']).' resume">'
            .'<div class="sp-doc"><div class="sp-doc-head"><p class="sp-doc-name">Sample Candidate</p><p class="sp-doc-role">'.self::e($r['t']).' · '.self::e($city).', India</p><p class="sp-doc-contact">Phone · Email · Profile link</p></div>'
            .'<p class="sp-doc-h">Professional summary</p><p>'.self::e($r['s']).'</p>'
            .'<p class="sp-doc-h">Experience</p><p class="sp-doc-job"><b>'.self::e($r['t']).'</b> · Employer · 2022 to now</p>'.self::ul($r['b'])
            .'<p class="sp-doc-h">Skills</p><p>'.self::e($skills).'</p>'
            .'<p class="sp-doc-h">Education</p><p>Degree, college, year</p></div>'
            .'<figcaption>Sample only. Replace every line and number with your own real work.</figcaption></figure>';
    }

    private static function image(string $kind, string $alt): array
    {
        return ['/assets/seo/'.$kind.'.svg', $alt];
    }

    /** Up to $n other roles in the same field, then the rest of the site's roles, for "related roles" lists. */
    private static function siblings(string $k, int $n = 5): array
    {
        $d = self::data();
        $out = array_filter(self::rolesIn($d['roles'][$k]['c']), fn ($_, $key) => $key !== $k, ARRAY_FILTER_USE_BOTH);

        return array_slice($out, 0, $n, true);
    }

    /** The cities that have a page for this role: [[path, city name, indexable]]. */
    private static function citiesFor(string $role): array
    {
        $out = [];
        foreach (self::data()['cities'] as $cs => $city) {
            foreach (self::cityRoles($cs) as $slug => $cr) {
                if ($cr['role'] === $role) {
                    $out[] = ["/resume/$cs/$slug/", $city['n'], $cr['note'] !== ''];
                }
            }
        }

        return $out;
    }

    /** Neighbouring categories (alphabetical wrap-around) for cross-links between fields. */
    private static function nearCategories(string $c, int $n = 4, bool $withRoles = false): array
    {
        $keys = array_keys(array_filter(self::data()['categories'], fn ($_, $k) => ! $withRoles || self::rolesIn($k), ARRAY_FILTER_USE_BOTH));
        $i = array_search($c, $keys, true);
        $out = [];
        for ($j = 1; $j <= count($keys) && count($out) < $n; $j++) {
            $k = $keys[(($i === false ? 0 : $i) + $j) % count($keys)];
            if ($k !== $c) {
                $out[] = $k;
            }
        }

        return $out;
    }

    private static function roleFaq(array $r): array
    {
        $t = self::lc($r['t']);

        return [
            ["What should a $t resume include?", 'A short summary, experience with measured results, a skills section that uses the words from the job post, then education and certifications. Recruiters in this field look for '.self::and(array_slice($r['k'], 0, 3)).'.'],
            ['Which skills should I list?', 'Only the ones you can show proof for. For this role that often means '.self::and(array_slice($r['k'], 3, 4)).'.'],
            ['How do I get past the applicant tracking system?', 'Use one column, standard headings such as Experience and Skills, and the exact skill names from the job description. Keep key details out of images, tables and icons.'],
            ['Can freshers apply?', $r['f']],
        ];
    }

    // ---------------------------------------------------------------- page builders

    private static function examplesHub(): array
    {
        $d = self::data();
        $cards = $items = [];
        foreach ($d['categories'] as $c => $cat) {
            $roles = self::rolesIn($c);
            $cards[] = ["/resume-examples/$c/", $cat['n'], $roles ? self::fit(implode(', ', array_map(fn ($r) => $r['t'], $roles)), 70) : $cat['h'][0]];
            $items[] = ["/resume-examples/$c/", $cat['n']];
        }

        return ['kind' => 'resume-examples', 'eyebrow' => 'RESUME EXAMPLES', 'title' => 'Resume Examples by Job & Industry (India)', 'description' => 'Resume examples for Indian job seekers, grouped by industry and role, with sample summaries, achievement bullet points, skills and interview questions.',
            'h1' => 'Resume examples for every field', 'lead' => 'Pick your field, then your role. Each example shows a filled sample, the skills recruiters look for and what to change for your own resume.',
            'crumbs' => self::crumbs(['Resume examples', '/resume-examples/']), 'image' => self::image('resume-examples', 'Resume examples grouped by industry, shown as sample resume pages'),
            'itemList' => $items,
            'sections' => [['fields', 'Browse by field', self::cards($cards)],
                ['how', 'How to use an example', self::ul(['Read what recruiters in your field check first.', 'Copy the structure, not the words. Write your own lines with your own results.', 'Match your skills section to the job post you are applying for.', 'Run a free scan by an '.self::ATS_DEF.' before you apply.'])]],
            'faq' => [['Can I copy a resume example?', 'Use it for structure and ideas only. Recruiters spot copied lines, and your resume must describe your real work.'], ['Which resume format is best in India?', 'Reverse-chronological (latest job first) suits most people. Freshers should put education and projects first.'], ['Do these examples work with applicant tracking systems?', 'Yes, when you keep a simple layout and use the job’s keywords. The examples here use standard headings.'], ['Are these examples free?', 'Yes. Reading examples and building your resume is free. A clean download needs a paid option.']],
            'related' => [['Other collections', [['/resume-templates/', 'Resume templates by style'], ['/cover-letter-examples/', 'Cover letter examples'], ['/interview-questions/', 'Interview questions'], ['/resume-builder/india/', 'Resume help by city']]], ['Guides', self::guides('ats', 'bullets', 'summary', 'fresher')]]];
    }

    private static function category(string $c): array
    {
        $d = self::data();
        $cat = $d['categories'][$c];
        $roles = self::rolesIn($c);
        $n = $cat['n'];
        $sections = [['recruiters', 'What recruiters in this field look for', '<p>'.self::e($cat['i']).'</p>'.self::ul($cat['h'], 'sp-check')],
            ['employers', 'Who hires', self::ul($cat['e'])]];
        if ($roles) {
            $sections[] = ['roles', 'Examples by role', self::cards(array_map(fn ($r, $k) => [self::rolePath($k), $r['t'], 'Key skills: '.implode(', ', array_slice($r['k'], 0, 3))], $roles, array_keys($roles)))];
        }
        $sections[] = ['tips', 'Tips for a stronger resume', self::ul(['Lead with a 2 to 3 line summary that names your role and years of experience.', 'Write each experience line as action + task + result with a number.', 'Name tools and standards exactly as job posts write them, so an '.self::ATS_DEF.' can match them.', 'Show '.self::lc($cat['h'][0]).' near the top, where a recruiter looks first.'])];
        $first = array_key_first($roles);
        $near = self::nearCategories($c, 4);

        return ['kind' => 'resume-examples', 'eyebrow' => 'RESUME EXAMPLES', 'title' => self::pick(["$n Resume Examples & Format", "$n Resume Examples", "$n Resumes"]), 'description' => self::sentences("$n resume examples for India: what recruiters check, who hires and sample resumes by role.", 'Recruiters look for '.self::lc(self::and(array_slice($cat['h'], 0, 2))).'.', 'Write yours free.'),
            'h1' => "$n resume examples", 'lead' => $cat['i'],
            'crumbs' => self::crumbs(['Resume examples', '/resume-examples/'], [$n, "/resume-examples/$c/"]), 'image' => self::image('resume-examples', "$n resume example layout with summary, experience and skills"),
            'itemList' => array_map(fn ($r, $k) => [self::rolePath($k), $r['t']], $roles, array_keys($roles)),
            'sections' => $sections, 'sample' => $first ? self::sample($roles[$first]) : null,
            'faq' => [['What is the best format for '.self::lc($n).' jobs?', 'Reverse-chronological with a short summary, then experience, skills and education. Freshers put education and projects first.'], ['What should I highlight?', implode('; ', $cat['h']).'.'], ['Do I need a cover letter?', 'It helps when you apply by email or for roles that ask for one. Keep it to 3 short paragraphs.'], ['Should I use a design template?', 'For portal applications use a simple, single-column layout. Use designed templates when you send your resume directly.']],
            'related' => [['More in this field', array_values(array_filter([$roles ? ["/cover-letter-examples/$c/", 'Cover letter examples'] : null, ...array_map(fn ($k) => ["/interview-questions/$k/", $roles[$k]['t'].' interview questions'], array_slice(array_keys($roles), 0, 2))]))],
                ['Other fields', array_map(fn ($k) => ["/resume-examples/$k/", $d['categories'][$k]['n']], $near)], ['Guides', self::guides('bullets', 'skills', 'ats')]]];
    }

    private static function role(string $k): array
    {
        $d = self::data();
        $r = $d['roles'][$k];
        $cat = $d['categories'][$r['c']];
        $t = $r['t'];
        $lt = self::lc($t);
        $siblings = self::siblings($k, 5);
        $cities = self::citiesFor($k);
        $sections = [
            ['summary', 'Writing the summary', '<p>The summary in the sample names the role, the years of experience, the main skills and one strength, in 2 or 3 lines. Swap in your own: for this job, name '.self::e(self::and(array_slice($r['k'], 0, 2))).' only if you have used them.</p>'],
            ['bullets', 'Writing experience bullet points', '<p>Each line in the sample is an action, the task and a result, such as “'.self::e(rtrim($r['b'][0], '.')).'”. Use your own numbers, and lead with the lines closest to the job post.</p>'],
            ['skills', "Skills and keywords for a $lt resume", '<p>These skills often appear in job posts for this role. List the ones you can prove, using the same words as the job description so an '.self::ATS_DEF.' can match them.</p>'.self::chips($r['k'])],
            ['recruiters', 'What recruiters check first', '<p>'.self::e($cat['i']).'</p>'.self::ul($cat['h'])],
            ['freshers', 'If you are a fresher', '<p>'.self::e($r['f']).'</p>'],
            ['interview', 'Prepare for the interview', '<p>Common questions for this role. Answer tips are on the <a href="/interview-questions/'.$k.'/">'.self::e($t).' interview questions</a> page.</p>'.self::ul(array_map(fn ($q) => $q[0], array_slice($r['q'], 0, 3)))],
        ];
        if ($cities) {
            $sections[] = ['cities', 'Local hiring notes by city', '<p>How employers in each city hire for this role:</p>'.self::links(array_map(fn ($c) => [$c[0], $c[1]], $cities))];
        }
        $skills2 = self::and(array_slice($r['k'], 0, 2));

        return ['kind' => 'resume-example', 'eyebrow' => mb_strtoupper($cat['n']), 'title' => self::pick(["$t Resume Example & Format", "$t Resume Example", "$t Resume"]),
            'description' => self::sentences("$t resume example for India with a sample summary and achievement lines.", "Skills recruiters search for, such as $skills2.", 'Build yours free.'),
            'h1' => "$t resume example", 'lead' => "A filled sample with a summary, experience lines and the skills recruiters search for. Use it as a guide, then write your own real results.",
            'crumbs' => self::crumbs(['Resume examples', '/resume-examples/'], [$cat['n'], "/resume-examples/{$r['c']}/"], [$t, self::rolePath($k)]),
            'image' => self::image('resume-example', "$t resume example showing summary, experience and skills sections"),
            'sample' => self::sample($r), 'sections' => $sections, 'faq' => self::roleFaq($r),
            'cta' => self::cta(['/register', 'Build my resume free'], ['/resume-templates/', 'Pick a template'], 'Start from this example’s structure in the builder, or upload your old resume. Building and previewing is free.'),
            'related' => [['Next steps for this role', [["/interview-questions/$k/", 'Interview questions and answers'], ["/cover-letter-examples/{$r['c']}/", $cat['n'].' cover letter'], ['/resume-templates/ats-friendly/', 'ATS-friendly templates']]],
                ['Related roles in '.$cat['n'], array_map(fn ($x, $key) => [self::rolePath($key), $x['t']], $siblings, array_keys($siblings))],
                ['Guides', self::guides('summary', 'bullets', 'skills', 'verbs')]]];
    }

    private static function templatesHub(): array
    {
        $d = self::data();
        $cards = array_map(fn ($x, $k) => ["/resume-templates/$k/", $x['n'], self::fit($x['w'], 80)], $d['templates'], array_keys($d['templates']));

        return ['kind' => 'template', 'eyebrow' => 'RESUME TEMPLATES', 'title' => 'Resume Templates by Style (India)', 'description' => 'Resume templates by style: ATS-friendly, simple, professional, modern, fresher and more. See who each suits and keep it readable for job portals.',
            'h1' => 'Resume templates by style', 'lead' => 'Pick the look that suits your field and how you apply. Every style can be edited online with a live preview, and your content stays when you switch.',
            'crumbs' => self::crumbs(['Resume templates', '/resume-templates/']), 'image' => self::image('resume-template', 'Resume template styles shown side by side'),
            'itemList' => array_map(fn ($c) => [$c[0], $c[1]], $cards),
            'sections' => [['styles', 'Choose a style', self::cards($cards)],
                ['choose', 'Which style should you pick?', self::ul(['Applying through job portals: one-column or ATS-friendly, so an '.self::ATS_DEF.' reads it in the right order.', 'Sending your resume directly to a person: modern, creative or minimalist can work.', 'Little work experience: the fresher or one-page layout puts education and projects first.', 'Long career: professional or corporate, with experience before education.'], 'sp-check')],
                ['gallery', 'See every design', '<p>The template gallery shows every design with a full preview, colours and fonts you can change.</p><p><a class="sp-text-link" href="/templates.html">Open the template gallery</a></p>']],
            'faq' => [['Are the templates free to use?', 'You can build, edit and preview for free. A clean, watermark-free download needs a paid option.'], ['Can I change the template later?', 'Yes. Your content stays when you switch designs.'], ['Which template is safest for job portals?', 'A single-column layout with standard headings.']],
            'related' => [['Examples to fill them with', [['/resume-examples/', 'Resume examples by field'], ['/resume-examples/student/freshers/', 'Freshers resume example'], ['/resume-words/', 'Better words for your resume']]], ['Guides', self::guides('ats', 'pdfword')]]];
    }

    private static function template(string $k): array
    {
        $d = self::data();
        $tp = $d['templates'][$k];
        $keys = array_keys($d['templates']);
        $i = array_search($k, $keys, true);
        $others = [];
        for ($j = 1; $j < count($keys) && count($others) < 6; $j++) {
            $o = $keys[($i + $j) % count($keys)];
            $others[] = ["/resume-templates/$o/", $d['templates'][$o]['n']];
        }
        $pick = ['fresher' => 'freshers', 'one-page' => 'sales-executive', 'one-column' => 'accountant', 'simple' => 'customer-support', 'creative' => 'graphic-designer', 'with-photo' => 'hospitality', 'classic' => 'teacher', 'professional' => 'financial-analyst', 'corporate' => 'operations-manager', 'minimalist' => 'product-manager', 'two-column' => 'data-analyst', 'modern' => 'digital-marketing-executive', 'freelance' => 'graphic-designer', 'senior' => 'sales-manager', 'career-change' => 'business-analyst', 'internship' => 'internship'][$k] ?? 'software-engineer';
        $pick = isset($d['roles'][$pick]) ? $pick : 'software-engineer';
        $audience = in_array($k, ['fresher', 'experienced', 'freelance', 'career-change', 'senior', 'internship'], true) ? $k : null;
        $names = $audience ? array_values(array_map(fn ($t) => $t['name'], array_filter(json_decode((string) file_get_contents(resource_path('data/catalog.json')), true)['templates'] ?? [], fn ($t) => ($t['audience'] ?? null) === $audience))) : [];
        $n = $tp['n'];
        $ln = self::lc($n);

        return ['kind' => 'template', 'eyebrow' => 'RESUME TEMPLATES', 'title' => self::pick(["$n Resume Templates", "$n Resume Template"]), 'description' => self::sentences("$n resume templates: who they suit, how to use them and how to keep them readable for job portals.", 'Best for: '.$tp['w'], 'Edit online with a live preview.'),
            'h1' => "$n resume templates", 'lead' => 'Best for: '.$tp['w'],
            'crumbs' => self::crumbs(['Resume templates', '/resume-templates/'], [$n, "/resume-templates/$k/"]), 'image' => self::image('resume-template', "$n resume template layout preview"),
            'sections' => [['features', 'What makes it work', self::ul($tp['u'], 'sp-check')],
                ['ats', 'Keeping it readable for software', '<p>An '.self::ATS_DEF.' reads your resume before a person does. '.self::e($tp['a']).'</p>'],
                ['designs', 'Designs to start from', '<p>Open the guided picker to see these designs with sample content for your own job. Choose your experience level and type any job title.</p>'.($names ? self::ul($names) : '').'<div class="sp-picks" data-picker-style="'.self::e($k).'" data-picker-role="'.self::e($pick).'"></div><p><a class="sp-text-link" href="/templates.html?style='.rawurlencode($k).'#picker">Open the '.self::e($ln).' designs in the guided picker</a></p>'],
                ['how', 'How to use it', '<ol class="sp-steps"><li>Open the template gallery and pick the '.self::e($ln).' layout.</li><li>Start from the design, or upload your old resume to fill it in.</li><li>Preview free, then download PDF or Word when you are ready.</li></ol><p><a class="sp-text-link" href="/templates.html">Open the template gallery</a></p>']],
            'sample' => self::sample($d['roles'][$pick]),
            'faq' => [["Who should use a $ln layout?", $tp['w']], ["Will a $ln layout pass every applicant tracking system?", 'No template can promise that. '.$tp['a']]],
            'related' => [['Other template styles', $others], ['Example to start from', [[self::rolePath($pick), $d['roles'][$pick]['t'].' resume example'], ['/resume-examples/', 'All resume examples']]], ['Guides', self::guides('ats', 'pdfword')]]];
    }

    private static function coverHub(): array
    {
        $d = self::data();
        $cards = $items = [];
        foreach ($d['categories'] as $c => $cat) {
            if (self::rolesIn($c)) {
                $cards[] = ["/cover-letter-examples/$c/", $cat['n'], 'Highlight: '.self::lc($cat['h'][0])];
                $items[] = ["/cover-letter-examples/$c/", $cat['n']];
            }
        }

        return ['kind' => 'cover-letter', 'eyebrow' => 'COVER LETTER EXAMPLES', 'title' => 'Cover Letter Examples by Industry', 'description' => 'Cover letter examples for Indian job seekers by industry, with a sample opening paragraph, what to highlight and a simple 3-paragraph structure.',
            'h1' => 'Cover letter examples by industry', 'lead' => 'A good cover letter is short: why this job, what you bring, and a polite close. Pick your field for a sample.',
            'crumbs' => self::crumbs(['Cover letter examples', '/cover-letter-examples/']), 'image' => self::image('cover-letter', 'Cover letter example with greeting, three paragraphs and sign-off'),
            'itemList' => $items,
            'sections' => [['structure', 'A simple structure', self::ul(['Paragraph 1: the role you are applying for and one line on why you fit.', 'Paragraph 2: two or three results from your experience that match the job.', 'Paragraph 3: a polite close and when you can join or talk.'], 'sp-check').'<p>Many employers read the letter after an '.self::ATS_DEF.' has shortlisted your resume, so check your resume first.</p>'],
                ['fields', 'Browse by field', self::cards($cards)],
                ['levels', 'Cover letters by experience level', self::cards(array_map(fn ($x) => ['/cover-letters.html?type='.$x[0].'#picker', $x[1], $x[2]], [['fresher', 'Freshers', 'Eager, learning-focused first-job letters'], ['experienced', 'Experienced', 'Results-led letters for your next step'], ['freelance', 'Freelance', 'Proposal-style letters to clients'], ['career-change', 'Career change', 'Letters that explain the switch'], ['senior', 'Senior and manager', 'Leadership letters with scope and results'], ['internship', 'Internship', 'Short letters for student applications']]))]],
            'faq' => [['How long should a cover letter be?', 'About 200 to 300 words, in 3 short paragraphs.'], ['Do Indian employers read cover letters?', 'Many read them for email applications and some portal roles. A short, specific letter can help you stand out.'], ['Should I write a new letter for every job?', 'Yes. Change the first and second paragraphs to match each job post.'], ['Can I send it in the email body?', 'Yes. For email applications, a short letter in the body plus your resume attached works well.']],
            'cta' => self::cta(['/register', 'Write my cover letter free'], ['/cover-letters.html', 'See letter designs']),
            'related' => [['Other collections', [['/resume-examples/', 'Resume examples'], ['/interview-questions/', 'Interview questions']]], ['Guides', self::guides('coverEmail', 'followup')]]];
    }

    private static function cover(string $c): array
    {
        $d = self::data();
        $cat = $d['categories'][$c];
        $roles = self::rolesIn($c);
        $first = array_key_first($roles);
        $n = $cat['n'];
        $letter = '<figure class="sp-sample sp-letter"><div class="sp-doc"><p>Dear Hiring Manager,</p><p>'.self::e($cat['cl']).'</p><p>In my current role I '.self::e(lcfirst(rtrim($roles[$first]['b'][0], '.'))).'. I would bring the same care to your team, along with strengths in '.self::e(self::and(array_slice($roles[$first]['k'], 0, 3))).'.</p><p>Thank you for your time. I would be glad to talk about how I can help. I can join within 30 days.</p><p>Yours sincerely,<br>Sample Candidate</p></div><figcaption>Sample only. Write your own letter with your real experience.</figcaption></figure>';
        $near = self::nearCategories($c, 4, true);

        return ['kind' => 'cover-letter', 'eyebrow' => 'COVER LETTER EXAMPLES', 'title' => self::pick(["$n Cover Letter Examples", "$n Cover Letter", "$n Cover Letters"]), 'description' => self::sentences("$n cover letter example with a sample opening and what to highlight:", self::lc(self::and(array_slice($cat['h'], 0, 2))).'.', 'Write yours free.'),
            'h1' => "$n cover letter examples", 'lead' => 'What to highlight: '.implode('; ', $cat['h']).'.',
            'crumbs' => self::crumbs(['Cover letter examples', '/cover-letter-examples/'], [$n, "/cover-letter-examples/$c/"]), 'image' => self::image('cover-letter', "$n cover letter example layout"),
            'sample' => $letter,
            'sections' => [['highlight', 'What to highlight', self::ul($cat['h'], 'sp-check')],
                ['employers', 'Who you may be writing to', self::ul($cat['e'])],
                ['tips', 'Tips', self::ul(['Name the exact role and where you saw it.', 'Pick 2 results that match the job post and give numbers.', 'Do not repeat your whole resume.', 'Keep the tone polite and simple.']).'<p>Recruiters often read the letter only after an '.self::ATS_DEF.' has shortlisted your resume, so use the job post’s key skills in both.</p>'],
                ['roles', 'Resume examples for this field', self::links(array_map(fn ($r, $k) => [self::rolePath($k), $r['t']], $roles, array_keys($roles)))]],
            'faq' => [['How do I start a '.self::lc($n).' cover letter?', 'Name the role and give one line on why you fit, as in the sample above.'], ['Should I mention salary?', 'Only if the job post asks. Otherwise discuss it later in the process.'], ['How do I end the letter?', 'Thank them, say you would like to talk, and mention your notice period if relevant.']],
            'cta' => self::cta(['/register', 'Write my cover letter free'], ['/cover-letters.html', 'See letter designs']),
            'related' => [['For this field', [["/resume-examples/$c/", "$n resume examples"], ["/interview-questions/$first/", $roles[$first]['t'].' interview questions']]],
                ['Other fields', array_map(fn ($k) => ["/cover-letter-examples/$k/", $d['categories'][$k]['n']], $near)], ['Guides', self::guides('coverEmail', 'followup')]]];
    }

    private static function interviewHub(): array
    {
        $d = self::data();
        $groups = '';
        $items = [];
        foreach ($d['categories'] as $c => $cat) {
            $roles = self::rolesIn($c);
            if ($roles) {
                $groups .= '<h3>'.self::e($cat['n']).'</h3>'.self::links(array_map(fn ($r, $k) => ["/interview-questions/$k/", $r['t']], $roles, array_keys($roles)));
                foreach ($roles as $k => $r) {
                    $items[] = ["/interview-questions/$k/", $r['t']];
                }
            }
        }

        return ['kind' => 'interview', 'eyebrow' => 'INTERVIEW QUESTIONS', 'title' => 'Interview Questions & Answers by Role', 'description' => 'Common interview questions with tips on how to answer, by job role, for freshers and experienced candidates in India.',
            'h1' => 'Interview questions by role', 'lead' => 'Each page lists common questions for the role and how to answer them in your own words.',
            'crumbs' => self::crumbs(['Interview questions', '/interview-questions/']), 'image' => self::image('interview', 'Interview question cards with answer tips'),
            'itemList' => $items,
            'sections' => [['general', 'What you will hear in most interviews', self::ul(['Tell me about yourself.', 'Why do you want this job?', 'What are your strengths and weaknesses?', 'Tell me about a challenge you handled.', 'What are your salary expectations?']).'<p>Interviews come after your resume clears the '.self::ATS_DEF.' and a recruiter’s first read, so prepare both.</p>'],
                ['roles', 'Browse by role', $groups]],
            'faq' => [['How should I prepare for an interview?', 'Read the job post, match 3 stories from your resume to it, practise answers out loud and prepare 2 questions to ask.'], ['What is the STAR method?', 'Situation, Task, Action, Result: a simple way to answer "tell me about a time" questions.'], ['How long should an answer be?', 'Usually 1 to 2 minutes. Stop when you have given the result.'], ['Should I memorise answers?', 'No. Know your stories and key numbers, then speak naturally.']],
            'related' => [['Other collections', [['/resume-examples/', 'Resume examples'], ['/cover-letter-examples/', 'Cover letter examples']]], ['Guides', self::guides('tmay', 'star', 'ask', 'salary', 'thanks')]]];
    }

    private static function interview(string $k): array
    {
        $d = self::data();
        $r = $d['roles'][$k];
        $t = $r['t'];
        $lt = self::lc($t);
        $qa = '<ol class="sp-qa">'.implode('', array_map(fn ($q) => '<li><h3>'.self::e($q[0]).'</h3><p><b>How to answer:</b> '.self::e($q[1]).'</p></li>', $r['q'])).'</ol>';
        $common = '<ol class="sp-qa"><li><h3>Tell me about yourself.</h3><p><b>How to answer:</b> In about a minute: your current role or degree, 1 or 2 results that fit this job, and why you want it.</p></li><li><h3>Why should we hire you?</h3><p><b>How to answer:</b> Match 3 of your strengths to the job post, for example '.self::e(self::and(array_slice($r['k'], 0, 3))).', with proof.</p></li><li><h3>What is your notice period?</h3><p><b>How to answer:</b> Give the exact period and whether early release is possible.</p></li></ol>';
        $siblings = self::siblings($k, 5);

        return ['kind' => 'interview', 'eyebrow' => 'INTERVIEW QUESTIONS', 'title' => self::pick(["$t Interview Questions & Answers", "$t Interview Questions", "$t Interview Q&A"]), 'description' => self::sentences("$t interview questions with tips on how to answer, such as “".rtrim($r['q'][0][0], '.?').'”.', 'Plus common HR questions.', 'Prepare with examples from your own resume.'),
            'h1' => "$t interview questions and answers", 'lead' => 'Questions that come up often for this role, with a short tip on how to answer each one. Use examples from your own work, not memorised lines.',
            'crumbs' => self::crumbs(['Interview questions', '/interview-questions/'], [$t, "/interview-questions/$k/"]), 'image' => self::image('interview', "$t interview questions with answer tips"),
            'sections' => [['role-questions', 'Questions about the role', $qa], ['hr-questions', 'Common HR questions', $common],
                ['prepare', 'How to prepare', self::ul(['Read the job post and list the skills it asks for.', 'Prepare 3 short stories from your resume using the STAR method.', 'Know the numbers on your resume and how you got them.', 'Prepare 2 questions to ask the interviewer.'])],
                ['skills', 'Skills interviewers often test', self::chips($r['k'])]],
            'faq' => [["What is asked in a $lt interview?", 'Role questions about your skills and past work, plus HR questions such as "Tell me about yourself" and notice period. See the list above.'], ['How should a fresher prepare?', $r['f']], ['What should I bring to the interview?', 'Copies of your resume, ID, certificates if asked, and a short list of questions for them.']],
            'cta' => self::cta(['/register', 'Update my resume free'], [self::rolePath($k), 'See the resume example'], 'Interviewers ask about what is on your resume. Make sure every line is one you can explain. Building and previewing is free.'),
            'related' => [['For this role', [[self::rolePath($k), "$t resume example"], ['/cover-letter-examples/'.$r['c'].'/', 'Cover letter example']]],
                ['Interview questions for related roles', array_map(fn ($x, $key) => ["/interview-questions/$key/", $x['t']], $siblings, array_keys($siblings))],
                ['Guides', self::guides('tmay', 'star', 'ask', 'thanks')]]];
    }

    /** Where to look for jobs in a place, worded for that place. Shown on state and city hub pages only. */
    private static function jobSources(string $where, array $ind): array
    {
        return ['Career pages of '.self::lc(self::and(array_slice($ind, 0, 2))).' employers in '.$where, 'Campus placement cells and alumni networks', 'Referrals from people already working in your field', 'The National Career Service portal run by the Government of India', 'Job portals and professional networking sites, with '.$where.' as the location filter'];
    }

    private static function india(): array
    {
        $d = self::data();
        $t1 = $t2 = [];
        foreach ($d['cities'] as $c => $city) {
            ${'t'.$city['tier']}[] = ["/resume-builder/$c/", $city['n']];
        }

        return ['kind' => 'place', 'eyebrow' => 'RESUME BUILDER INDIA', 'title' => 'Resume Builder for India: Free to Start', 'description' => 'Build an ATS-friendly resume for jobs in India. Pick your state or city for local job market tips, role examples and where to find jobs.',
            'h1' => 'Resume builder for jobs in India', 'lead' => 'Build a resume that Indian recruiters and job portals can read easily. Start free, then choose your state or city for local tips.',
            'crumbs' => self::crumbs(['Resume builder India', '/resume-builder/india/']), 'image' => self::image('india', 'Map-style card of Indian cities covered by CVTara resume guides'),
            'itemList' => array_merge(array_map(fn ($s, $k) => ["/resume-builder/$k/", $s['n']], $d['states'], array_keys($d['states'])), $t1, $t2),
            'sections' => [['format', 'A format that works in India', self::ul(['Name, phone, email, city and a professional profile link at the top. Date of birth, religion and marital status are not needed.', 'A 2 to 3 line summary naming your role and years of experience.', 'Experience, latest first, with results in numbers.', 'Skills that match the job post, so an '.self::ATS_DEF.' can find them.', 'Education with year and score if it helps you.'], 'sp-check')],
                ['states', 'Choose your state', self::cards(array_map(fn ($s, $k) => ["/resume-builder/$k/", $s['n'], implode(', ', array_slice($s['ind'], 0, 2))], $d['states'], array_keys($d['states'])))],
                ['tier1', 'Major cities', self::links($t1)], ['tier2', 'Growing cities', self::links($t2)]],
            'faq' => [['Is it free?', 'Building, editing and previewing is free. A clean, watermark-free PDF or Word download needs a paid option.'], ['Should I add a photo on my resume in India?', 'Usually no, unless the job post asks for one.'], ['Is a CV the same as a resume in India?', 'Most Indian employers use the words the same way. A long academic CV is different.'], ['How many pages should my resume be?', 'One page for freshers and early careers, up to two for experienced professionals.']],
            'related' => [['Start here', [['/resume-examples/', 'Resume examples by field'], ['/resume-templates/', 'Resume templates by style'], ['/interview-questions/', 'Interview questions']]], ['Guides', self::guides('ats', 'fresher', 'ctc')]]];
    }

    private static function state(string $s): array
    {
        $d = self::data();
        $st = $d['states'][$s];
        $n = $st['n'];
        $others = array_values(array_filter(array_keys($d['states']), fn ($k) => $k !== $s));

        return ['kind' => 'place', 'eyebrow' => mb_strtoupper($n), 'title' => self::pick(["Resume Builder $n: Local Job Tips", "Resume Builder $n"]), 'description' => self::sentences("Resume tips for jobs in $n: main industries, the {$st['psc']} route and city guides.", 'Free ATS-friendly resume builder.'),
            'h1' => "Resume builder for jobs in $n", 'lead' => 'Main industries: '.implode('; ', $st['ind']).'. Choose a city below for local tips and role examples.',
            'crumbs' => self::crumbs(['Resume builder India', '/resume-builder/india/'], [$n, "/resume-builder/$s/"]), 'image' => self::image('india', "Resume guide card for $n"),
            'sections' => [['industries', 'Industries that hire', self::ul($st['ind'])],
                ['cities', 'City guides', '<p>Each city guide covers its own employers, local tips and roles:</p>'.self::cards(array_map(fn ($c) => ["/resume-builder/$c/", $d['cities'][$c]['n'], implode(', ', array_slice($d['cities'][$c]['ind'], 0, 3))], $st['cities']))],
                ['government', 'Government jobs', '<p>State government posts are usually filled through the '.self::e($st['psc']).' and other state recruitment boards. Always apply in the format given in the official notification. A separate resume helps for contract roles, PSUs and interviews.</p><p><a class="sp-text-link" href="/resume/'.$s.'/government-jobs/">Government jobs resume for '.self::e($n).'</a></p>'],
                ['tips', 'Resume tips', '<p>Put your city next to your contact details so recruiters filtering by place find you, and list '.self::e($st['lang']).' and the other languages you speak with your level in each. Employers in '.self::e(self::lc($st['ind'][0])).' often check exact qualifications and dates, so keep them precise.</p>']],
            'faq' => [["What resume format works for jobs in $n?", 'A reverse-chronological resume with a short summary, results in numbers and skills that match the job post.'], ['Which cities in '.$n.' have their own guide?', self::and(array_map(fn ($c) => $d['cities'][$c]['n'], $st['cities'])).'. Each one lists local employer types, tips and roles.'], ['Should I mention '.$st['lang'].'?', 'Yes, list each language and your level. It helps in customer-facing and field roles.']],
            'related' => [['Related', [['/resume-builder/india/', 'All states and cities'], ["/resume/$s/government-jobs/", 'Government jobs resume'], ['/resume-examples/government/', 'Government resume examples']]],
                ['Other states', array_map(fn ($k) => ["/resume-builder/$k/", $d['states'][$k]['n']], array_slice($others, 0, 5))], ['Guides', self::guides('ats', 'scams')]]];
    }

    private static function stateGov(string $s): array
    {
        $d = self::data();
        $st = $d['states'][$s];
        $n = $st['n'];
        $r = $d['roles']['government-jobs'];
        $keys = array_keys($d['states']);
        $i = array_search($s, $keys, true);
        $others = array_map(fn ($j) => $keys[($i + $j) % count($keys)], range(1, 4));
        $cityLinks = array_map(fn ($c) => ["/resume-builder/$c/", $d['cities'][$c]['n']], $st['cities']);
        $cityGov = array_values(array_filter(array_map(fn ($c) => isset(self::cityRoles($c)['government-jobs']) ? ["/resume/$c/government-jobs/", 'Government jobs in '.$d['cities'][$c]['n']] : null, $st['cities'])));
        $cityNames = self::and(array_map(fn ($c) => $d['cities'][$c]['n'], $st['cities']));

        return ['kind' => 'place-role', 'eyebrow' => mb_strtoupper($n).' · GOVERNMENT JOBS', 'title' => self::pick(["Government Jobs Resume $n", "Govt Jobs Resume $n"]), 'description' => self::sentences("Resume format for government and PSU jobs in $n: how the {$st['psc']} recruits and what to include.", 'Exact dates, marks and languages.'),
            'h1' => "Government jobs resume for $n", 'lead' => 'Most state posts are filled through the '.$st['psc'].' and state recruitment boards, using the format in each official notification. Keep a resume ready for contract roles, PSUs, interviews and document checks.',
            'crumbs' => self::crumbs(['Resume builder India', '/resume-builder/india/'], [$n, "/resume-builder/$s/"], ['Government jobs', "/resume/$s/government-jobs/"]), 'image' => self::image('resume-example', "Government jobs resume sample for $n"),
            'sections' => [['how', 'How state recruitment works', '<p>Read each notification on the official '.self::e($st['psc']).' website or the relevant state board, apply online in the given format before the last date, then clear the written exam, skill test or interview it lists. Bring original documents for verification.</p><p>Contract posts, PSUs and many interviews still ask for a resume.</p>'],
                ['include', 'What to put on the resume', '<p>Give qualifications with year, board or university and marks exactly as on your certificates, exact start and end dates for every job, duties in plain words, and your level in '.self::e($st['lang']).' and English. Add computer skills and typing speed if the post needs them. The <a href="/resume-examples/government/government-jobs/">government jobs resume example</a> shows a filled sample.</p>'],
                ['private', 'Private employers too', '<p>The same resume, with a stronger summary and results, works for private employers in '.self::e(self::lc(self::and(array_slice($st['ind'], 0, 3)))).'. City guides: '.self::e($cityNames).'.</p>'.self::links(array_merge($cityLinks, $cityGov))]],
            'faq' => [['Which language skills help?', $st['lang'].' and English are useful for most state posts and for customer-facing private roles. List your level in each.'], ['Where do I check official notices?', 'On the official website of the '.$st['psc'].' or the relevant recruitment board. Do not pay anyone who promises a government job.']],
            'cta' => self::cta(['/register', 'Build a formal resume free'], ['/resume-templates/classic/', 'See classic templates']),
            // The ten state pages differ only in the commission, language and cities, so they would compete as near-copies.
            // The state hub carries the same facts and is the page offered to search engines; this one stays noindex,follow.
            'index' => false,
            'related' => [['Related', [["/resume-builder/$s/", "All $n guides"], ['/resume-examples/government/government-jobs/', 'Government jobs resume example'], ['/interview-questions/government-jobs/', 'Government job interview questions']]],
                ['Other states', array_map(fn ($k) => ["/resume/$k/government-jobs/", $d['states'][$k]['n']], $others)], ['Guides', self::guides('scams', 'ats')]]];
    }

    private static function city(string $c): array
    {
        $d = self::data();
        $city = $d['cities'][$c];
        $n = $city['n'];
        $st = self::stateOf($c);
        $stName = $st ? self::stateName($st) : $city['st'];
        $roles = [];
        foreach (self::cityRoles($c) as $slug => $cr) {
            $roles[] = ["/resume/$c/$slug/", $slug === 'freshers' ? 'Freshers' : $d['roles'][$cr['role']]['t'], $cr['note'] ? self::fit($cr['note'], 90) : self::fit($d['roles'][$cr['role']]['s'], 80)];
        }
        $near = [];
        foreach ($d['cities'] as $k => $o) {
            if ($k !== $c && $o['st'] === $city['st']) {
                $near[] = ["/resume-builder/$k/", $o['n']];
            }
        }
        foreach ($d['cities'] as $k => $o) {
            if ($k !== $c && $o['st'] !== $city['st'] && $o['tier'] === $city['tier'] && count($near) < 6) {
                $near[] = ["/resume-builder/$k/", $o['n']];
            }
        }
        $crumbs = [['Resume builder India', '/resume-builder/india/']];
        if ($st) {
            $crumbs[] = [$stName, "/resume-builder/$st/"];
        }
        $crumbs[] = [$n, "/resume-builder/$c/"];

        return ['kind' => 'place', 'eyebrow' => mb_strtoupper($n).', '.mb_strtoupper($stName), 'title' => self::pick(["Resume Builder $n: Local Job Tips", "Resume Builder $n"]), 'description' => self::sentences("Build a resume for jobs in $n. Who hires (".self::lc(self::and(array_slice($city['ind'], 0, 2))).'), local tips and role examples.', 'Free ATS-friendly builder.'),
            'h1' => "Resume builder for jobs in $n", 'lead' => "$n hires across ".self::lc(self::and(array_slice($city['ind'], 0, 3))).'. Here is how to shape your resume for local employers.',
            'crumbs' => self::crumbs(...$crumbs), 'image' => self::image('city', "Resume guide card for jobs in $n"),
            'itemList' => array_map(fn ($x) => [$x[0], $x[1]], $roles),
            'sections' => [['market', 'The local job market', '<p>Employer types you will meet in '.self::e($n).':</p>'.self::ul($city['ind'])],
                ['roles', 'In-demand roles and local notes', self::cards($roles)],
                ['tips', 'Resume tips for '.$n, self::ul(array_merge($city['tips'], ['Write "'.$n.', '.$stName.'" as your location so recruiters filtering by city find you.', 'Use a single-column layout with standard headings so an '.self::ATS_DEF.' can read it.']), 'sp-check')],
                ['sources', 'Where to find jobs', self::ul(self::jobSources($n, $city['ind']))],
                ['nearby', 'Other city guides', self::links($near)]],
            'faq' => [["Which roles have a local guide for $n?", self::and(array_map(fn ($x) => $x[1], $roles)).'. Each page has local hiring notes and what to show.'], ["Should I put $n on my resume?", 'Yes. Add your city and state near your contact details. If you are relocating, say so and when.'], ["What is the best format for jobs in $n?", 'Reverse-chronological with a short summary, results in numbers and skills that match the job post. Freshers lead with education and projects.']],
            'related' => [['Nearby and related', array_values(array_filter([$st ? ["/resume-builder/$st/", "All $stName guides"] : null, ['/resume-builder/india/', 'All states and cities'], ["/resume/$c/freshers/", "Freshers in $n"]]))], ['Guides', self::guides('ats', 'col', 'ctc')]]];
    }

    private static function cityRole(string $c, string $slug): array
    {
        $d = self::data();
        $city = $d['cities'][$c];
        $n = $city['n'];
        $cr = self::cityRoles($c)[$slug];
        $r = $d['roles'][$cr['role']];
        $fresh = $slug === 'freshers';
        $t = $fresh ? 'Freshers' : $r['t'];
        $lt = $fresh ? 'fresher' : self::lc($r['t']);
        $st = self::stateOf($c);
        $stName = $st ? self::stateName($st) : $city['st'];
        $local = $cr['note'] !== '';
        // Same role elsewhere: cities in the same state first, then pages with a local note.
        $others = [];
        foreach ($d['cities'] as $k => $o) {
            if ($k !== $c && isset(self::cityRoles($k)[$slug])) {
                $others[] = [($o['st'] === $city['st'] ? 0 : 2) - (self::cityRoles($k)[$slug]['note'] !== '' ? 1 : 0), "/resume/$k/$slug/", $o['n']];
            }
        }
        usort($others, fn ($a, $b) => $a[0] <=> $b[0]);
        $others = array_map(fn ($x) => [$x[1], $x[2]], array_slice($others, 0, 5));
        $sameCity = [];
        foreach (self::cityRoles($c) as $s2 => $x) {
            if ($s2 !== $slug) {
                $sameCity[] = ["/resume/$c/$s2/", $s2 === 'freshers' ? 'Freshers' : $d['roles'][$x['role']]['t']];
            }
        }
        $note = $local ? $cr['note'] : ($fresh
            ? "Freshers in $n compete for entry roles in ".self::lc(self::and(array_slice($city['ind'], 0, 2))).'. A one-page resume that shows projects, internships and skills gets read faster.'
            : ucfirst($lt).' roles in '.$n.' come from employers such as '.self::lc(self::and(array_slice($city['ind'], 0, 2))).'. Show the local market knowledge and languages that help you serve them.');
        $crumbs = [['Resume builder India', '/resume-builder/india/']];
        if ($st) {
            $crumbs[] = [$stName, "/resume-builder/$st/"];
        }
        $crumbs[] = [$n, "/resume-builder/$c/"];
        $crumbs[] = [$t, "/resume/$c/$slug/"];
        $show = $fresh
            ? self::ul(['Education first: degree, college, year and score if it helps.', 'Two or three projects or internships, each with what you built or did and a result.', 'Skills you can prove, using the words from the job post.', 'Certifications, languages and any part-time work.'], 'sp-check')
            : self::ul(array_slice($r['b'], 0, 2), 'sp-check').'<p>Skills to name if you have them:</p>'.self::chips(array_slice($r['k'], 0, 6));
        $sections = [['local', "Hiring in $n", '<p>Employer types in '.self::e($n).': '.self::e(self::lc(self::and($city['ind']))).'.</p>'],
            ['local-tips', 'Shape it for local employers', self::ul(array_merge($city['tips'], ['Write "'.$n.', '.$stName.'" as your location. If you are moving, add "Relocating to '.$n.'" with a month.']), 'sp-check')],
            ['show', $fresh ? 'What to show with no experience' : 'What to show', $show.'<p><a class="sp-text-link" href="'.self::e(self::rolePath($cr['role'])).'">See the full example with a filled sample</a></p>'],
            ['interview', 'Questions to prepare', self::ul(array_map(fn ($q) => $q[0], array_slice($r['q'], 0, 2))).'<p><a class="sp-text-link" href="/interview-questions/'.$cr['role'].'/">More questions with answer tips</a></p>']];
        $faq = [["What should my resume for $n include?", "Your city and state near the top, a short summary, ".($fresh ? 'projects and internships' : 'experience').' with results, skills such as '.self::and(array_slice($r['k'], 0, 2)).", and education. Recruiters in $n often filter by location."],
            ["How do I apply for jobs in $n from another city?", "Add \"Relocating to $n\" with a month near your contact details and say it in your cover letter."]];
        if (! $fresh) {
            $faq[] = ['Can freshers apply?', $r['f']];
        }
        $label = $fresh ? "Resume for Freshers in $n" : "$t Resume in $n";

        return ['kind' => 'place-role', 'eyebrow' => mb_strtoupper($n).' · '.mb_strtoupper($t), 'title' => self::pick([$label, $fresh ? "Freshers Resume $n" : "$t Resume $n", "$t, $n"]),
            'description' => self::sentences($fresh ? "Resume for freshers in $n." : "$t resume for jobs in $n.", $note, 'Local tips, what to show and questions to prepare.', 'Local tips and what to show.'),
            'h1' => $fresh ? "Resume for freshers in $n" : "$t resume for jobs in $n", 'lead' => $note,
            'crumbs' => self::crumbs(...$crumbs), 'image' => self::image('city', "$t resume guide for $n"),
            // A page is offered to search engines only when it carries a local note. The rest stay useful for visitors and
            // pass their links on (noindex,follow) instead of competing as near-copies of each other.
            'index' => $local,
            'sections' => $sections, 'faq' => $faq,
            'related' => [['For this role', [[self::rolePath($cr['role']), 'Full resume example'], ['/interview-questions/'.$cr['role'].'/', 'Interview questions'], ['/cover-letter-examples/'.$r['c'].'/', 'Cover letter example']]],
                ['Same role in other cities', $others],
                ["Other roles in $n", array_merge([["/resume-builder/$c/", "All $n guides"]], array_slice($sameCity, 0, 5))]]];
    }

    private static function wordsHub(): array
    {
        $w = self::data()['words'];

        return ['kind' => 'words', 'eyebrow' => 'RESUME WORDS', 'title' => 'Resume Words: Better Synonyms to Use', 'description' => 'Stronger words for your resume: alternatives to overused words like learn, help, manage and responsible for, with before and after examples.',
            'h1' => 'Better words for your resume', 'lead' => 'Overused words make strong work sound ordinary. Pick a word to see alternatives and a before-and-after example.',
            'crumbs' => self::crumbs(['Resume words', '/resume-words/']), 'image' => self::image('words', 'Resume word swaps from weak to strong'),
            'itemList' => array_map(fn ($x, $k) => ["/resume-words/$k/", $x['w']], $w, array_keys($w)),
            'sections' => [['words', 'Choose a word', self::cards(array_map(fn ($x, $k) => ["/resume-words/$k/", '“'.$x['w'].'”', 'Try: '.implode(', ', array_slice($x['s'], 0, 3))], $w, array_keys($w)))],
                ['rule', 'The rule that matters more than the word', '<p>A better verb helps, but a result helps more. Write action + task + result: "Cut report time by 10 hours a week by automating 12 Excel reports" says more than any single word.</p><p>An '.self::ATS_DEF.' mostly matches skills and job titles. Strong verbs help the person who reads your resume next.</p>']],
            'faq' => [['What are action verbs on a resume?', 'Words at the start of a line that show what you did, such as led, built or reduced.'], ['Should every line start with a different verb?', 'Try to vary them, but clarity matters more than variety.'], ['Is it fine to use the same words as the job post?', 'Yes, for skills and job titles. It helps matching.'], ['Which words should I avoid?', 'Vague claims such as hard-working or passionate without proof. Show it with a result instead.']],
            'related' => [['Put the words to use', [['/resume-examples/', 'Resume examples by field'], ['/resume-templates/', 'Resume templates']]], ['Guides', self::guides('verbs', 'bullets', 'summary')]]];
    }

    private static function word(string $k): array
    {
        $all = self::data()['words'];
        $w = $all[$k];
        $lw = mb_strtolower($w['w']);
        $keys = array_keys($all);
        $i = array_search($k, $keys, true);
        $others = array_map(fn ($j) => $keys[($i + $j) % count($keys)], range(1, 6));
        $s = $w['s'];

        return ['kind' => 'words', 'eyebrow' => 'RESUME WORDS', 'title' => self::pick(["Another Word for \"$w[w]\" on a Resume", "Synonyms for \"$w[w]\" on a Resume", "\"$w[w]\" Synonyms for Resumes"]), 'description' => self::sentences("Synonyms for \"$lw\" on a resume, such as ".self::lc(self::and(array_slice($s, 0, 3))).', with a before-and-after example.', 'Replace weak words with specific actions and results.'),
            'h1' => "Another word for “{$lw}” on a resume", 'lead' => 'Meaning on a resume: '.$w['m'],
            'crumbs' => self::crumbs(['Resume words', '/resume-words/'], [$w['w'], "/resume-words/$k/"]), 'image' => self::image('words', "Alternatives to the word $lw on a resume"),
            'sections' => [['synonyms', 'Words to use instead', self::chips($s)],
                ['example', 'Before and after', '<div class="sp-before-after"><p><span>Before</span>'.self::e($w['ex'][0]).'</p><p><span>After</span>'.self::e($w['ex'][1]).'</p></div><p>The better line names the task and gives a result. Use your own real numbers.</p>'],
                ['how', 'Choosing between them', '<p>Pick the most accurate one. '.self::e($s[0]).' and '.self::e($s[1]).' are strong when you can show a result; '.self::e(mb_strtolower($s[2])).' or '.self::e(mb_strtolower($s[3])).' suit plainer lines. Put the word at the start of the line, follow it with the task and a measurable result, and avoid using any one word more than twice on a page.</p>']],
            'faq' => [["Is “{$lw}” a bad word for a resume?", 'Not bad, but often vague. A more specific word such as '.mb_strtolower($s[0]).', plus a result, reads stronger.'], ['Can I use these words in a cover letter?', 'Yes. The same rule applies: be specific and give proof.'], ['Will these words help with an applicant tracking system?', 'Matching depends mostly on skills and job titles. Strong verbs help the person who reads your resume next.']],
            'related' => [['More resume words', array_map(fn ($key) => ["/resume-words/$key/", '“'.$all[$key]['w'].'”'], $others)], ['Guides', self::guides('verbs', 'bullets')]]];
    }

    private static function atsChecker(): array
    {
        return ['kind' => 'tool', 'eyebrow' => 'FREE ATS CHECK', 'title' => 'Free ATS Resume Checker for India', 'description' => 'Check how well an applicant tracking system can read your resume, and how it matches a job description. See missing keywords and what to fix.',
            'h1' => 'Free ATS resume checker', 'lead' => 'An '.self::ATS_DEF.' reads your resume before a recruiter does. CVTara checks how readable your resume is and how well it matches a job post, then shows what to fix.',
            'crumbs' => self::crumbs(['ATS checker', '/ats-checker/']), 'image' => self::image('ats', 'ATS resume check report with matched and missing keywords'),
            'sections' => [['what', 'What the check looks at', self::ul(['Standard section names such as Experience, Education and Skills', 'Readable text (not text inside images)', 'Contact details in the right place', 'Keywords and skills from the job description: matched, partly matched and missing', 'Simple fixes you can make in the builder'], 'sp-check')],
                ['how', 'How it works', '<ol class="sp-steps"><li><b>Add your resume.</b> Upload a PDF or Word file, or build one from a template.</li><li><b>Add the job.</b> Paste the job description or add the job link.</li><li><b>See the report.</b> A match estimate, missing keywords and the top fixes.</li><li><b>Fix and recheck.</b> Edit in the builder and run the check again.</li></ol>'],
                ['limits', 'What no checker can promise', '<p>Every employer configures their system differently, so no tool can guarantee a shortlist. The check shows clear, explainable gaps so you can fix them before you apply.</p>'],
                ['fix', 'Fix what it finds', self::links([['/resume-templates/ats-friendly/', 'ATS-friendly templates'], ['/resume-templates/one-column/', 'One-column templates'], ['/resume-examples/', 'Resume examples by field'], ['/resume-words/', 'Stronger resume words'], ['/guides/resume/skills-section-keywords/', 'Choosing skills and keywords']])]],
            'faq' => [['Is the ATS check free?', 'Free accounts get a few checks every day. Plans include more scans.'], ['What score is good?', 'Aim to fix every missing must-have skill you really have. A higher match helps, but honesty matters more than a number.'], ['Does a two-column resume fail ATS?', 'Not always, but some systems read columns in the wrong order. A single column is safest.'], ['Which file type should I upload?', 'A text-based PDF or Word file. Scanned images cannot be read.']],
            'cta' => self::cta(['/register', 'Check my resume free'], ['/resume-templates/ats-friendly/', 'ATS-friendly templates']),
            'related' => [['Related', [['/resume-templates/', 'Resume templates by style'], ['/resume-examples/', 'Resume examples'], ['/interview-questions/', 'Interview questions']]], ['Guides', self::guides('ats', 'skills', 'pdfword')]]];
    }

    // ---------------------------------------------------------------- output

    /** JSON-LD blocks: BreadcrumbList, FAQPage and WebPage on every page, plus an ItemList on hubs. No LocalBusiness markup. */
    public static function schema(array $p, string $base): array
    {
        $ld = [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn ($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $base.$c[1]], $p['crumbs'], array_keys($p['crumbs']))]];
        if (! empty($p['faq'])) {
            $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $p['faq'])];
        }
        $page = ['@context' => 'https://schema.org', '@type' => empty($p['itemList']) ? 'WebPage' : 'CollectionPage', 'name' => $p['h1'], 'description' => $p['description'], 'url' => $base.$p['path'], 'image' => $base.$p['image'][0], 'inLanguage' => 'en-IN', 'publisher' => ['@type' => 'Organization', 'name' => 'CVTara', 'logo' => $base.'/assets/brand/cvtara-logo.webp']];
        if (! empty($p['itemList'])) {
            $page['mainEntity'] = ['@type' => 'ItemList', 'numberOfItems' => count($p['itemList']), 'itemListElement' => array_map(fn ($x, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $base.$x[0], 'name' => $x[1]], $p['itemList'], array_keys($p['itemList']))];
        }
        $ld[] = $page;

        return $ld;
    }

    public static function render(array $p): string
    {
        return view('seo.page', ['p' => $p])->render();
    }
}
